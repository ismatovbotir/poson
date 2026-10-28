<?php

namespace App\Livewire\Site\Cart\Concerns;

use App\Actions\Analytics\RecordAddToCart;
use App\Actions\Cart\AddToCart;
use App\Support\Cart\CartToken;
use App\Support\Site\Analytics;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * "Add to cart" for site components (Site\Cart\AddButton, the header
 * Site\Cart\Counter for product cards, Catalog\RollPicker):
 *
 *  1. the "cart-add" limit: CART_ADD_LIMIT attempts per minute per IP
 *     (failed ones count too);
 *  2. without a writable token (a new cart), the "cart-new" limit:
 *     CART_NEW_LIMIT new carts per hour per IP, so cookie-less clients can't
 *     create carts without end (security review M1);
 *  3. AddToCart with the visitor's token, or a new one that is only sent as
 *     the cookie once the add succeeded (lazy cart); after every successful
 *     add the cookie is sent again with a fresh 30-day expiry (rolling);
 *  4. "cart-updated" for the header counter, the add_to_cart analytics row
 *     (RecordAddToCart: product id only, staff skipped, never throws) and,
 *     only while the site tracks visitors (Site\Analytics), the browser
 *     event "poson-add-to-cart" that resources/js/analytics.js turns into a
 *     dataLayer / gtag add_to_cart (SKU and quantity, no price or PII).
 *
 * Errors land on the "cart" error key.
 */
trait AddsToCart
{
    /**
     * "cart-add" limiter: attempts per IP per minute (plan 11 v2 §6).
     */
    public const CART_ADD_LIMIT = 60;

    public const CART_ADD_DECAY_SECONDS = 60;

    /**
     * "cart-new" limiter: new carts per IP per hour.
     */
    public const CART_NEW_LIMIT = 10;

    public const CART_NEW_DECAY_SECONDS = 3600;

    /**
     * @return bool Whether the line was added
     */
    protected function addProductToCart(int $productId, ?int $rollTypeId, int $qty, ?string $sku = null, bool $notifyCounter = true): bool
    {
        $this->resetErrorBag('cart');

        if (! static::cartAddAllowed()) {
            $this->addError('cart', __('checkout.cart.errors.rate_limited', ['seconds' => RateLimiter::availableIn(static::cartAddLimiterKey())]));

            return false;
        }

        $tokens = app(CartToken::class);
        $token = $tokens->writable();
        $isNew = $token === null;

        if ($isNew && RateLimiter::hit(static::cartNewLimiterKey(), self::CART_NEW_DECAY_SECONDS) > self::CART_NEW_LIMIT) {
            $this->addError('cart', __('checkout.cart.errors.rate_limited', ['seconds' => RateLimiter::availableIn(static::cartNewLimiterKey())]));

            return false;
        }

        $token ??= CartToken::generate();

        try {
            app(AddToCart::class)->handle($token, $productId, $rollTypeId, $qty, app()->getLocale());
        } catch (ValidationException $exception) {
            $this->addError('cart', (string) collect($exception->errors())->flatten()->first());

            return false;
        }

        // New cart: the first cookie; existing cart: the same token with a fresh expiry.
        $tokens->issue($token);

        if ($notifyCounter) {
            $this->dispatch('cart-updated');
        }

        app(RecordAddToCart::class)->handle(request(), $productId, static::currentPageUrl(), app()->getLocale());

        if ($sku !== null && app(Analytics::class)->config() !== null) {
            $this->dispatch('poson-add-to-cart', sku: $sku, quantity: $qty);
        }

        return true;
    }

    /**
     * Count an attempt; false once the IP is over the limit.
     */
    protected static function cartAddAllowed(): bool
    {
        return RateLimiter::hit(static::cartAddLimiterKey(), self::CART_ADD_DECAY_SECONDS) <= self::CART_ADD_LIMIT;
    }

    protected static function cartAddLimiterKey(): string
    {
        return 'cart-add:'.sha1((string) request()->ip());
    }

    protected static function cartNewLimiterKey(): string
    {
        return 'cart-new:'.sha1((string) request()->ip());
    }

    /**
     * The page the component is on (the original URL on Livewire update requests).
     */
    protected static function currentPageUrl(): string
    {
        try {
            return (string) Livewire::originalUrl();
        } catch (\Throwable) {
            return url()->current();
        }
    }

    /**
     * An integer (also from a numeric string), else null.
     */
    protected static function cartIntOrNull(mixed $value): ?int
    {
        $int = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $int === false ? null : $int;
    }
}
