<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Cart\CartLineKey;
use App\Support\Cart\CartPricer;
use App\Support\Cart\PricedLine;
use App\Support\NumberFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Add a product (or a label product in a roll type) to the visitor's cart
 * (plan 11 v2 §1.1, security conditions 6, 7, 14, 15 and 17).
 *
 * Accepted only while the cart is enabled and the product is on the site,
 * sold through the cart (sale mode cart / both), has a Retail price and is
 * in stock or on order; a label product only in an attached, active roll
 * type (RollPricing::isAvailable()), any other product never with one
 * (CartPricer::evaluate(), shared with the pricing). qty must be
 * min_qty..Cart::MAX_QTY (step 1), also after adding to an existing line;
 * a cart holds at most Cart::MAX_LINES lines.
 *
 * The cart row is created on the first add (lazy) and locked for the
 * upsert, so concurrent adds can't pass the line cap or duplicate a line
 * (unique cart_id + line_key). Errors are ValidationExceptions on "cart".
 */
class AddToCart
{
    /**
     * @throws ValidationException
     */
    public function handle(string $token, int $productId, ?int $rollTypeId, int $qty, string $locale): CartItem
    {
        if (! config('site.cart_enabled')) {
            throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.disabled')]);
        }

        if (! Str::isUlid($token)) {
            throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.unavailable')]);
        }

        $product = Product::query()->with('rollTypes')->find($productId);
        $rollType = $rollTypeId !== null && $product instanceof Product ? $product->rollTypes->firstWhere('id', $rollTypeId) : null;

        static::assertBuyable($product, $rollTypeId, $rollType, $qty);

        $lineKey = CartLineKey::make($productId, $rollTypeId);

        // attempts: 3 — two first adds of one token can deadlock on MySQL gap locks
        // (select … for update on a missing row, then insert); the retry sees the row.
        return DB::transaction(function () use ($token, $locale, $lineKey, $product, $rollTypeId, $rollType, $qty): CartItem {
            $cart = static::lockCart($token, $locale);

            if ($cart->isConverted()) {
                throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.closed')]);
            }

            $item = CartItem::query()->where('cart_id', $cart->id)->where('line_key', $lineKey)->first();

            if ($item instanceof CartItem) {
                $total = $item->qty + $qty;
                static::assertBuyable($product, $rollTypeId, $rollType, $total);
                $item->update(['qty' => $total]);

                return $item;
            }

            if (CartItem::query()->where('cart_id', $cart->id)->count() >= Cart::MAX_LINES) {
                throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.too_many_lines', ['max' => Cart::MAX_LINES])]);
            }

            return CartItem::query()->create([
                'cart_id' => $cart->id,
                'line_key' => $lineKey,
                'product_id' => $product->id,
                'roll_type_id' => $rollTypeId,
                'qty' => $qty,
            ]);
        }, attempts: 3);
    }

    /**
     * The cart of a token, created when missing, locked for this transaction.
     */
    public static function lockCart(string $token, string $locale): Cart
    {
        $cart = Cart::query()->where('token', $token)->lockForUpdate()->first();

        if ($cart instanceof Cart) {
            return $cart;
        }

        $locale = in_array($locale, config('app.supported_locales'), true) ? $locale : config('app.locale');

        // A concurrent first add may have created it: createOrFirst() falls back to that row.
        Cart::query()->createOrFirst(['token' => $token], ['locale' => $locale]);

        return Cart::query()->where('token', $token)->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws ValidationException
     */
    public static function assertBuyable(?Product $product, ?int $rollTypeId, ?RollType $rollType, int $qty): void
    {
        $result = CartPricer::evaluate($product, $rollTypeId, $rollType, $qty);

        if ($result['problem'] === PricedLine::PROBLEM_UNAVAILABLE) {
            throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.unavailable')]);
        }

        if ($result['problem'] === PricedLine::PROBLEM_QUANTITY) {
            throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.quantity', [
                'min' => NumberFormat::format(CartPricer::minQty($product)),
                'max' => NumberFormat::format(Cart::MAX_QTY),
            ])]);
        }
    }
}
