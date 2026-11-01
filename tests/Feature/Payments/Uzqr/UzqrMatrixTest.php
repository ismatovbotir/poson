<?php

use App\Actions\Orders\PlaceOrder;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMode;
use App\Models\Order;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use Illuminate\Validation\ValidationException;
use Tests\Support\Uzqr;

beforeEach(function () {
    uzqrUploaded();
});

/**
 * @return list<string>
 */
function uzqrMatrix(bool $checkout = true, bool $test = false, bool $ikpu = true, int $total = 1_000_000_00, DeliveryType $delivery = DeliveryType::CourierRegion): array
{
    $context = new PaymentContext($delivery, BuyerType::Individual, $total, $ikpu, $test);
    $options = app(PaymentOptions::class);

    return array_map(fn (PaymentMethod $method): string => $method->value, $checkout ? $options->forCheckout($context) : $options->for($context));
}

it('replaces static UzQR on the public checkout once dynamic is live; staff still see both', function () {
    Uzqr::configure();

    expect(uzqrMatrix())->toBe(['uzqr_dynamic'])
        ->and(uzqrMatrix(checkout: false))->toBe(['uzqr_static', 'uzqr_dynamic']);
});

it('offers dynamic UzQR only up to the online cap', function () {
    Uzqr::configure();

    expect(uzqrMatrix(total: 60_000_000_00))->toBe(['uzqr_static']);
});

it('needs no IKPU, webhook or public APP_URL for dynamic UzQR', function () {
    Uzqr::configure();
    config(['app.url' => 'http://poson.test']);

    expect(uzqrMatrix(ikpu: false))->toContain('uzqr_dynamic');
});

it('falls back to static UzQR when dynamic is not usable', function (Closure $break) {
    Uzqr::configure();
    $break();

    expect(uzqrMatrix())->toBe(['uzqr_static']);
})->with([
    'gate closed' => [fn () => Uzqr::configure(gateOpen: false)],
    'no INN' => [fn () => Uzqr::configure(env: ['services.uzqr.inn' => null])],
    'invalid INN' => [fn () => Uzqr::configure(env: ['services.uzqr.inn' => '12345'])],
    'no key' => [fn () => Uzqr::configure(env: ['services.uzqr.api_key' => null])],
    'http base URL' => [fn () => config(['uzqr.base_urls.prod' => 'http://api.uzqr.example.uz'])],
    'auth failed' => [fn () => app(UzqrConfig::class)->flagAuthFailed(PaymentMode::Prod)],
    'test mode active' => [fn () => Uzqr::configure('test')],
    'open cap' => [function () {
        config(['checkout.uzqr_max_open' => 1]);
        UzqrTransaction::factory()->create();
    }],
    'hold not registered' => [fn () => config(['checkout.payment_holds' => []])],
]);

it('keeps mode isolation: real orders only in prod, TEST orders only in test', function () {
    Uzqr::configure('prod');
    expect(uzqrMatrix(test: true, checkout: false))->not->toContain('uzqr_dynamic');

    Uzqr::configure('test');
    expect(uzqrMatrix(test: true, checkout: false))->toContain('uzqr_dynamic')
        ->and(uzqrMatrix(test: false))->not->toContain('uzqr_dynamic');
});

it('forces prod without a separate https test host and rejects a test key equal to the live key', function () {
    Uzqr::configure('test', testHost: false);
    $config = app(UzqrConfig::class);
    expect($config->activeMode())->toBe(PaymentMode::Prod)->and($config->testAvailable())->toBeFalse();

    config(['uzqr.base_urls.test' => Uzqr::PROD.'/test']);
    expect($config->testAvailable())->toBeFalse();

    Uzqr::configure('test', ['services.uzqr.test_api_key' => Uzqr::KEY]);
    expect($config->ready(PaymentMode::Test))->toBeFalse();
});

it('places a checkout order with dynamic UzQR and refuses static while dynamic is offered', function () {
    Uzqr::configure();
    $cart = orderCart();

    expect(fn () => app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, ['paymentMethod' => 'uzqr_static'])))->toThrow(ValidationException::class);

    $order = app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, ['paymentMethod' => 'uzqr_dynamic']));
    expect($order->payment_method)->toBe(PaymentMethod::UzqrDynamic)->and(Order::count())->toBe(1);
});
