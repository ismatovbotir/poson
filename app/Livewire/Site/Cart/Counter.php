<?php

namespace App\Livewire\Site\Cart;

use App\Livewire\Site\Cart\Concerns\AddsToCart;
use App\Models\CartItem;
use App\Models\Product;
use App\Support\Cart\CartPricer;
use App\Support\Cart\CartToken;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Header cart link with the number of lines (plan 11a), refreshed on
 * "cart-updated". Reads only the line count of the visitor's open cart
 * (no query without the cookie); holds no cart id or prices.
 *
 * It also takes "Add to cart" from product cards ("cart-add" with the
 * product id, one minimum quantity; label products link to their page
 * instead) so listings need no component per card, and shows a short
 * notice ("Added to cart" or the error).
 */
class Counter extends Component
{
    use AddsToCart;

    #[On('cart-updated')]
    public function refreshCount(): void
    {
        // Re-render only.
    }

    #[On('cart-add')]
    public function addFromCard(mixed $productId = null): void
    {
        $id = static::cartIntOrNull($productId);
        $product = $id !== null && $id > 0
            ? Product::query()->visible()->find($id, ['id', 'sku', 'pricing_unit', 'min_qty', 'is_active', 'category_id'])
            : null;

        // Label products are added with their roll on the product page; other products at their minimum.
        if (! $product instanceof Product || $product->isPricedPerLabel()) {
            $this->dispatch('cart-notice', message: __('checkout.cart.errors.unavailable'), error: true);

            return;
        }

        // The counter re-renders after its own action: no "cart-updated" round trip.
        if ($this->addProductToCart($product->id, null, CartPricer::minQty($product), $product->sku, notifyCounter: false)) {
            $this->dispatch('cart-notice', message: __('checkout.cart.added'), error: false);

            return;
        }

        $this->dispatch('cart-notice', message: (string) $this->getErrorBag()->first('cart'), error: true);
    }

    public function render(): View
    {
        $token = app(CartToken::class)->current();

        $count = $token !== null
            ? CartItem::query()->whereHas('cart', fn ($cart) => $cart->open()->where('token', $token))->count()
            : 0;

        return view('livewire.site.cart.counter', ['count' => $count]);
    }
}
