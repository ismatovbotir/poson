<?php

namespace App\Support\Cart;

use App\Models\Cart;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * The visitor's cart token (plan 11 v2 §1.1, security conditions 14 and 16):
 * an opaque ULID in the "poson_cart" cookie, encrypted by the web group's
 * EncryptCookies, HttpOnly, Secure in production, SameSite=Lax, 30 days.
 *
 * Lazy: nothing is issued until the first successful "Add to cart"
 * (writable() → generate() → AddToCart → issue()), so browsing the site,
 * or a rejected add, never creates a cookie or a carts row. A token issued
 * in this request is remembered (scoped binding), so the rest of the
 * request (e.g. the header counter) already sees it. Every successful
 * add / change / removal sends the cookie again (refresh()), so the 30 days
 * run from the last change (rolling).
 */
class CartToken
{
    public const COOKIE = 'poson_cart';

    public const DAYS = 30;

    private ?string $issued = null;

    /**
     * The visitor's token, or null without a (well-formed) cookie.
     */
    public function current(): ?string
    {
        if ($this->issued !== null) {
            return $this->issued;
        }

        $token = request()->cookie(self::COOKIE);

        return is_string($token) && Str::isUlid($token) ? strtoupper($token) : null;
    }

    /**
     * The token to add to: the current one, or null when the visitor has none
     * yet or an order was already placed from that cart (plan 11b; a new
     * cart starts then).
     */
    public function writable(): ?string
    {
        $token = $this->current();

        if ($token !== null && $this->issued === null && Cart::query()->where('token', $token)->whereNotNull('converted_at')->exists()) {
            return null;
        }

        return $token;
    }

    /**
     * A new random token (not sent yet: see issue()).
     */
    public static function generate(): string
    {
        return strtoupper((string) Str::ulid());
    }

    /**
     * Send a token as the cookie with this response and use it for the rest of the request.
     */
    public function issue(string $token): void
    {
        $this->issued = $token;

        Cookie::queue(self::cookie($token));
    }

    /**
     * Send the current token again with a fresh 30-day expiry (rolling
     * cookie, after a successful change); nothing without a token.
     */
    public function refresh(): void
    {
        $token = $this->current();

        if ($token !== null) {
            $this->issue($token);
        }
    }

    /**
     * The visitor's open cart, or null (no token, no row yet, or converted).
     */
    public function cart(): ?Cart
    {
        $token = $this->current();

        return $token !== null ? Cart::query()->open()->where('token', $token)->first() : null;
    }

    /**
     * The cookie carrying a token (encrypted later by EncryptCookies).
     */
    public static function cookie(string $token): SymfonyCookie
    {
        return Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: self::DAYS * 24 * 60,
            path: '/',
            domain: null,
            secure: app()->isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: SymfonyCookie::SAMESITE_LAX,
        );
    }
}
