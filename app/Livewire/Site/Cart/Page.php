<?php

namespace App\Livewire\Site\Cart;

use App\Actions\Cart\RemoveCartLine;
use App\Actions\Cart\UpdateCartLine;
use App\Livewire\Site\Cart\Concerns\AddsToCart;
use App\Models\Cart;
use App\Support\Cart\CartLineKey;
use App\Support\Cart\CartPricer;
use App\Support\Cart\CartToken;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The cart page /{locale}/cart (plan 11a; 404 while config
 * site.cart_enabled is off, noindex, disallowed in robots.txt).
 *
 * Rendered from CartPricer on every request (Retail prices re-read from
 * the products). Security condition 7: no cart id, prices, totals or
 * validity flags live in public properties; lines are named only by their
 * key ("p12" / "p12-r3"), parsed with CartLineKey::PATTERN in every
 * method. Lines that can no longer be bought are flagged "No longer
 * available" (left out of the total) and can only be removed; on-order
 * products note that the manager confirms the lead time.
 *
 * "Request a quote for this cart": the Site\Cart\QuoteForm child (plan 42;
 * inline in #cart-quote, also opened in Site\LeadModal).
 * "Checkout" leads to /{locale}/checkout (plan 11b) while every line can be bought.
 */
class Page extends Component
{
    use AddsToCart;

    public function boot(): void
    {
        abort_unless(config('site.cart_enabled'), 404);
    }

    public function increment(mixed $lineKey): void
    {
        $qty = $this->currentQty($lineKey);

        if ($qty !== null) {
            $this->changeQty($lineKey, $qty + 1);
        }
    }

    public function decrement(mixed $lineKey): void
    {
        $qty = $this->currentQty($lineKey);

        if ($qty !== null) {
            $this->changeQty($lineKey, $qty - 1);
        }
    }

    /**
     * A typed quantity; empty or not a number leaves the line as it is.
     */
    public function updateQty(mixed $lineKey, mixed $qty): void
    {
        $qty = is_float($qty) ? (int) $qty : static::cartIntOrNull($qty);

        if ($qty === null || $this->currentQty($lineKey) === null) {
            return;
        }

        $this->changeQty($lineKey, max(1, min(Cart::MAX_QTY, $qty)));
    }

    public function remove(mixed $lineKey, RemoveCartLine $removeLine): void
    {
        $cart = $this->cart();

        if ($cart === null || ! CartLineKey::isValid($lineKey)) {
            return;
        }

        try {
            $removeLine->handle($cart, $lineKey);
        } catch (ValidationException $exception) {
            $this->addError('cart', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        app(CartToken::class)->refresh();
        $this->dispatch('cart-updated');
    }

    public function render(CartPricer $pricer): View
    {
        $cart = $this->cart();
        $priced = $cart !== null ? $pricer->price($cart) : null;

        return view('livewire.site.cart.page', [
            'cart' => $priced,
            'maxQty' => Cart::MAX_QTY,
            'renderId' => Str::random(8),
            'breadcrumbs' => [
                ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
                ['label' => __('checkout.cart.title'), 'url' => null],
            ],
        ])
            ->title(__('checkout.cart.title').' — '.__('site.meta.site_name'))
            ->layoutData([
                'description' => __('checkout.cart.seo_description'),
                'robots' => 'noindex,nofollow',
            ]);
    }

    private function changeQty(mixed $lineKey, int $qty): void
    {
        $cart = $this->cart();

        if ($cart === null || ! CartLineKey::isValid($lineKey)) {
            return;
        }

        $this->resetErrorBag('cart');

        if (! static::cartAddAllowed()) {
            $this->addError('cart', __('checkout.cart.errors.rate_limited', ['seconds' => RateLimiter::availableIn(static::cartAddLimiterKey())]));

            return;
        }

        try {
            app(UpdateCartLine::class)->handle($cart, $lineKey, $qty);
        } catch (ValidationException $exception) {
            $this->addError('cart', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        app(CartToken::class)->refresh();
        $this->dispatch('cart-updated');
    }

    /**
     * The quantity of a line of the visitor's cart; null for a malformed or unknown key.
     */
    private function currentQty(mixed $lineKey): ?int
    {
        if (! CartLineKey::isValid($lineKey)) {
            return null;
        }

        $qty = $this->cart()?->items()->where('line_key', $lineKey)->value('qty');

        return $qty !== null ? (int) $qty : null;
    }

    private function cart(): ?Cart
    {
        return app(CartToken::class)->cart();
    }
}
