<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramUser;
use App\Support\Telegram\MiniApp\TmaCart;
use App\Support\Telegram\MiniApp\TmaSession;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 security review (M1–M4): M1 refused sign-ins, L1 delivery fees, L2 attempts,
 * L3 framing of 419 / 404, L4 no GC lottery, L6 dontFlash, L8 error page.
 */
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
});

it('ends the previous account session on every refused sign-in (M1)', function (Closure $initData) {
    $previous = tmaUser();
    tmaSignIn($previous);
    $this->get('/tma/uz')->assertOk();
    $oldToken = session()->token();

    tmaAuth($initData());

    expect(session(TmaSession::USER))->toBeNull()
        ->and(session()->token())->not->toBe($oldToken);
    $this->get('/tma/uz')->assertRedirect(route('tma.bootstrap'));
})->with([
    'unregistered user B' => [fn () => tmaInitData(TelegramUser::factory()->unregistered()->create()->telegram_id)],
    'unknown user B' => [fn () => tmaInitData(777000222)],
    'banned user B' => [fn () => tmaInitData(TelegramUser::factory()->registered()->banned()->create()->telegram_id)],
    'blocked user B' => [fn () => tmaInitData(TelegramUser::factory()->registered()->blocked()->create()->telegram_id)],
    'forged initData' => [fn () => str_replace('hash=', 'hash=0', tmaInitData(5550001))],
    'switch off' => [function () {
        tmaEnabled(miniApp: false);

        return tmaInitData(tmaUser(['phone' => '+998907654321'])->telegram_id);
    }],
]);

it('refuses a checkout after a region fee changed since the page was shown (L1)', function () {
    config(['site.cart_enabled' => true]);
    checkoutSettings(['region_fees' => ['samarkand' => 50_000]]);
    $user = tmaUser();
    tmaSignIn($user);
    $cart = TmaCart::for($user, 'uz');
    CartItem::factory()->for($cart)->create(['product_id' => Product::factory()->withIkpu()->create()->id, 'qty' => 1]);

    preg_match('/name="delivery_fingerprint" value="([0-9a-f]{64})"/', $this->get('/tma/uz/checkout')->assertOk()->getContent(), $shown);
    preg_match('/name="cart_fingerprint" value="([0-9a-f]{64})"/', $this->get('/tma/uz/checkout')->getContent(), $cartShown);

    checkoutSettings(['region_fees' => ['samarkand' => 90_000]]);

    $this->post('/tma/uz/checkout', [
        'cart_fingerprint' => $cartShown[1], 'delivery_fingerprint' => $shown[1], 'name' => 'Aziz Karimov',
        'buyer_type' => 'individual', 'delivery_type' => 'pickup', 'payment_method' => 'on_receipt',
    ])->assertSessionHasErrors('fingerprint');

    expect(Order::query()->count())->toBe(0);
});

it('counts every checkout submission first against a per-user attempts limit (L2)', function () {
    config(['site.cart_enabled' => true]);
    $user = tmaUser();
    tmaSignIn($user);
    CartItem::factory()->for(TmaCart::for($user, 'uz'))->create(['product_id' => Product::factory()->create()->id, 'qty' => 1]);

    foreach (range(1, 20) as $attempt) {
        $this->post('/tma/uz/checkout', ['cart_fingerprint' => 'stale'])->assertSessionHasErrors('fingerprint');
    }

    $this->post('/tma/uz/checkout', ['cart_fingerprint' => 'stale'])->assertSessionHasErrors('form')->assertSessionDoesntHaveErrors('fingerprint');
    expect(RateLimiter::attempts('tma-checkout:'.$user->id))->toBe(0);
});

it('denies framing of 419 and route-binding 404 responses too (L3)', function () {
    $this->get('/uz/product/no-such-product')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');

    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        $this->post('https://poson.example.uz/uz/careers/apply/sales', [])->assertStatus(419)->assertHeader('X-Frame-Options', 'DENY');
    } finally {
        Request::setTrustedHosts([]);
        app()['env'] = $previous;
    }
});

it('never runs session garbage collection from a Mini App request (L4)', function () {
    Route::middleware('tma')->get('/tma/__lottery', fn () => response(json_encode(config('session.lottery'))));

    expect(json_decode($this->get('/tma/__lottery')->getContent(), true))->toBe([0, 100])
        ->and(config('session.lottery'))->not->toBe([0, 100]);
});

it('never flashes init_data with a validation error (L6, MA-11)', function () {
    Route::middleware('web')->post('/__tma-flash', fn () => throw ValidationException::withMessages(['x' => 'bad']));

    $this->from('/uz')->post('/__tma-flash', ['init_data' => 'query_id=AAHdF6IQ&hash=d2c3', 'other' => 'kept'])->assertRedirect('/uz');

    expect(session('_old_input'))->toBe(['other' => 'kept'])
        ->and(json_encode(session()->all()))->not->toContain('AAHdF6IQ');
});

it('renders Mini App errors with the CSP-safe page (L8)', function (string $url, int $status) {
    $user = tmaUser();
    tmaSignIn($user);

    $response = $this->get($url)->assertStatus($status);
    $html = $response->getContent();

    expect($html)->toContain('data-error="'.$status.'"')
        ->not->toContain('style=')
        ->not->toMatch('/<style\b/i')
        ->not->toContain('livewire')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors https://web.telegram.org');
})->with([
    'missing product' => ['/tma/uz/p/no-such-product', 404],
    'foreign order' => ['/tma/ru/orders/999999', 404],
]);

it('answers 429 on a Mini App page with the same page', function () {
    $user = tmaUser();
    tmaSignIn($user);

    foreach (range(1, 120) as $hit) {
        RateLimiter::hit(md5('tma'.'tma:u'.$user->id), 60);
    }

    // The named limiter's key: md5(name + Limit key), see TmaSession::limiterKey().
    expect($this->get('/tma/uz')->assertStatus(429)->getContent())->toContain('data-error="429"')->not->toContain('style=');
});

it('keeps JSON errors as JSON', function () {
    $this->postJson('/tma/auth', [])->assertForbidden()->assertJsonStructure(['redirect']);
});

it('lets a ready response through the error page unchanged', function () {
    Route::middleware('tma')->get('/tma/__ready', fn () => throw new HttpResponseException(response('x', 202)));

    $this->get('/tma/__ready')->assertStatus(202)->assertContent('x');
});
