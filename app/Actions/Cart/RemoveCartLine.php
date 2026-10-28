<?php

namespace App\Actions\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Support\Cart\CartLineKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remove a line from a cart by its key (checked against
 * CartLineKey::PATTERN), under the cart row lock. Removing a line that is
 * already gone is not an error.
 */
class RemoveCartLine
{
    /**
     * @throws ValidationException
     */
    public function handle(Cart $cart, string $lineKey): void
    {
        if (CartLineKey::parse($lineKey) === null) {
            throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.line_missing')]);
        }

        DB::transaction(function () use ($cart, $lineKey): void {
            $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();

            if ($locked->isConverted()) {
                throw ValidationException::withMessages(['cart' => __('checkout.cart.errors.closed')]);
            }

            CartItem::query()->where('cart_id', $locked->id)->where('line_key', $lineKey)->delete();
            $locked->touch();
        });
    }
}
