<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Support\Cart\CartLineKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change the quantity of a cart line (plan 11 v2 §1.1). The line is named
 * by its key only ("p12" / "p12-r3", checked against CartLineKey::PATTERN);
 * the new quantity must be min_qty..Cart::MAX_QTY and the line must still
 * be buyable (an unavailable line can only be removed). Runs under the
 * cart row lock. Errors are ValidationExceptions on "cart".
 */
class UpdateCartLine
{
    /**
     * @throws ValidationException
     */
    public function handle(Cart $cart, string $lineKey, int $qty): CartItem
    {
        if (CartLineKey::parse($lineKey) === null) {
            throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.line_missing')]);
        }

        return DB::transaction(function () use ($cart, $lineKey, $qty): CartItem {
            $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            if ($locked->isConverted()) {
                throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.closed')]);
            }

            $item = CartItem::query()->where('cart_id', $locked->id)->where('line_key', $lineKey)->first();

            if (! $item instanceof CartItem) {
                throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.line_missing')]);
            }

            $product = Product::query()->withTrashed()->with('rollTypes')->find($item->product_id);
            $rollType = $item->roll_type_id !== null ? $product?->rollTypes->firstWhere('id', $item->roll_type_id) : null;

            AddToCart::assertBuyable($product, $item->roll_type_id, $rollType, $qty);

            $item->update(['qty' => $qty]);

            return $item;
        });
    }
}
