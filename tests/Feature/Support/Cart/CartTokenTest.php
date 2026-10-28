<?php

use App\Models\Cart;
use App\Support\Cart\CartToken;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

it('builds an HttpOnly, SameSite=Lax, 30-day cookie that is Secure in production', function (string $environment, bool $secure) {
    app()->detectEnvironment(fn () => $environment);
    $token = CartToken::generate();

    $cookie = CartToken::cookie($token);

    expect($cookie->getName())->toBe('poson_cart')
        ->and($cookie->getValue())->toBe($token)
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBe($secure)
        ->and($cookie->getSameSite())->toBe(SymfonyCookie::SAMESITE_LAX)
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->isRaw())->toBeFalse()
        ->and($cookie->getExpiresTime())->toBeGreaterThanOrEqual(now()->addDays(30)->getTimestamp() - 5)
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->getTimestamp() + 5);
})->with([
    'production' => ['production', true],
    'local' => ['local', false],
]);

it('is not excluded from cookie encryption', function () {
    expect(app(EncryptCookies::class)->isDisabled(CartToken::COOKIE))->toBeFalse();
});

it('generates opaque ULIDs and issues them only on request', function () {
    $tokens = app(CartToken::class);

    expect($tokens->current())->toBeNull()
        ->and(Cookie::getQueuedCookies())->toBe([]);

    $token = CartToken::generate();
    expect(Str::isUlid($token))->toBeTrue()
        ->and(Cookie::getQueuedCookies())->toBe([]);

    $tokens->issue($token);

    expect($tokens->current())->toBe($token)
        ->and(Cookie::queued(CartToken::COOKIE)?->getValue())->toBe($token);
});

it('reads the token from the cookie and ignores malformed values', function (string $value, bool $accepted) {
    request()->cookies->set(CartToken::COOKIE, $value);

    expect(app(CartToken::class)->current())->toBe($accepted ? strtoupper($value) : null);
})->with([
    'ulid' => [(string) Str::ulid(), true],
    'lowercase ulid' => [strtolower((string) Str::ulid()), true],
    'numeric id' => ['12', false],
    'garbage' => ['<script>', false],
]);

it('starts a new cart instead of writing to a converted one', function () {
    $cart = Cart::factory()->converted()->create();
    request()->cookies->set(CartToken::COOKIE, $cart->token);

    expect(app(CartToken::class)->writable())->toBeNull()
        ->and(app(CartToken::class)->cart())->toBeNull();
});
