<?php

namespace App\Support\Chat;

use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * The website chat's visitor token (plan 28 C5 §19.3, CB-18, WC-5). A
 * scoped binding, like CartToken.
 *
 *  - Token: random_bytes(32) as base64url, exactly 43 characters; any other
 *    shape is no token (checked before hashing). Stored only as its sha256
 *    (web_chats.token_hash, unique index), never in the database, a log, the
 *    HTML or a Livewire snapshot.
 *  - Cookie: "__Host-poson_chat" in production (Secure, Path=/, no Domain,
 *    so a subdomain can neither read nor plant it), "poson_chat" elsewhere;
 *    HttpOnly, SameSite=Lax, chatbot.web.cookie_minutes (1 day), re-sent
 *    with every visitor message. Encrypted by the web group's EncryptCookies
 *    (never in its except list; the value is prefixed with the cookie's own
 *    name, so a value copied from another cookie does not decrypt). The
 *    cookie is built here, never through CookieJar::make(), so the session
 *    domain is never added. Not bound to the IP (mobile IPs change).
 *  - Lazy: nothing is issued before the visitor's first message (StartWebChat).
 */
class WebChatToken
{
    public const COOKIE = 'poson_chat';

    public const HOST_COOKIE = '__Host-poson_chat';

    public const LENGTH = 43;

    private ?string $issued = null;

    private bool $forgotten = false;

    public static function cookieName(): string
    {
        return app()->isProduction() ? self::HOST_COOKIE : self::COOKIE;
    }

    /**
     * The visitor's token, or null without a well-formed cookie.
     */
    public function current(): ?string
    {
        if ($this->issued !== null) {
            return $this->issued;
        }

        if ($this->forgotten) {
            return null;
        }

        $token = request()->cookie(self::cookieName());

        return self::isWellFormed($token) ? $token : null;
    }

    public static function isWellFormed(mixed $token): bool
    {
        return is_string($token) && strlen($token) === self::LENGTH && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1;
    }

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Send a token with this response and use it for the rest of the request.
     */
    public function issue(string $token): void
    {
        $this->issued = $token;
        $this->forgotten = false;

        Cookie::queue(self::cookie($token, (int) config('chatbot.web.cookie_minutes', 1440)));
    }

    /**
     * Send the current token again with a fresh lifetime (rolling).
     */
    public function refresh(): void
    {
        $token = $this->current();

        if ($token !== null) {
            $this->issue($token);
        }
    }

    /**
     * Drop the cookie (a closed or expired chat; never a banned one, WC-14).
     */
    public function forget(): void
    {
        $this->issued = null;
        $this->forgotten = true;

        Cookie::queue(self::cookie('', -2628000));
    }

    public static function cookie(string $value, int $minutes): SymfonyCookie
    {
        return new SymfonyCookie(
            name: self::cookieName(),
            value: $value,
            expire: $minutes === 0 ? 0 : now()->addMinutes($minutes)->getTimestamp(),
            path: '/',
            domain: null,
            secure: app()->isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: SymfonyCookie::SAMESITE_LAX,
        );
    }
}
