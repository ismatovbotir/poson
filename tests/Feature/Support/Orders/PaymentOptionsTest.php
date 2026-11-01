<?php

use App\Enums\BuyerType;
use App\Enums\DeliveryPricing;
use App\Enums\DeliveryType;
use App\Enums\PaymentMethod;
use App\Enums\Region;
use App\Models\Quote;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Support\CompanySettings;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\DeliveryQuote;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use App\Support\Orders\TaxId;
use App\Support\Vat;

function paymentOptions(DeliveryType $delivery, BuyerType $buyer = BuyerType::Individual, int $total = 1_000_000_00, bool $ikpu = true, bool $test = false): array
{
    return array_map(fn (PaymentMethod $method): string => $method->value, app(PaymentOptions::class)->for(new PaymentContext($delivery, $buyer, $total, $ikpu, $test)));
}

it('computes VAT included at 12% in integer tiyin and Quote delegates to it', function () {
    expect(Vat::of(112_00))->toBe(12_00)
        ->and(Vat::of(1_000_000_00))->toBe(107_142_86)
        ->and(Quote::vatOf(1_000_000_00))->toBe(Vat::of(1_000_000_00));
});

it('applies the payment matrix per delivery type and buyer', function (DeliveryType $delivery, BuyerType $buyer, array $expected) {
    sellerRequisites();
    uzqrUploaded();

    expect(paymentOptions($delivery, $buyer))->toBe($expected);
})->with([
    'pickup, person' => [DeliveryType::Pickup, BuyerType::Individual, ['uzqr_static', 'on_receipt']],
    'kokand, company' => [DeliveryType::CourierKokand, BuyerType::Company, ['uzqr_static', 'on_receipt', 'bank_transfer']],
    'region, person' => [DeliveryType::CourierRegion, BuyerType::Individual, ['uzqr_static']],
    'region, YaTT' => [DeliveryType::CourierRegion, BuyerType::SoleProprietor, ['uzqr_static', 'bank_transfer']],
]);

it('hides static UzQR without an uploaded code and bank transfer without requisites', function () {
    expect(paymentOptions(DeliveryType::Pickup, BuyerType::Company))->toBe(['on_receipt']);
});

it('never offers Payme or Click while their webhooks are not built, in test mode or for TEST orders', function () {
    config(['app.url' => 'https://poson.uz']);
    freshSecrets(['services.payme.mode' => 'prod', 'services.payme.merchant_id' => '587f72c72cac0d162c722ae2', 'services.payme.key' => 'fake-payme-live-key-0000']);
    $options = app(PaymentOptions::class);

    expect($options->providerReady(PaymentMethod::Payme, false))->toBeFalse()
        ->and($options->providerReady(PaymentMethod::Payme, true))->toBeFalse()
        ->and($options->rejection(PaymentMethod::Payme, new PaymentContext(DeliveryType::Pickup, BuyerType::Individual, 100_00, true)))->toBe('provider')
        ->and($options->rejection(PaymentMethod::UzqrDynamic, new PaymentContext(DeliveryType::Pickup, BuyerType::Individual, 100_00, true)))->toBe('provider');
});

it('rejects online payment above the online cap and without IKPU once a provider is live', function () {
    $options = Mockery::mock(PaymentOptions::class, [app(CheckoutSettings::class), app(CompanySettings::class), app(UzqrConfig::class)])->makePartial();
    $options->shouldReceive('providerReady')->andReturn(true);
    checkoutSettings(['max_online_total' => 50_000_000]);

    expect($options->rejection(PaymentMethod::Click, new PaymentContext(DeliveryType::Pickup, BuyerType::Individual, 50_000_000_00, true)))->toBeNull()
        ->and($options->rejection(PaymentMethod::Click, new PaymentContext(DeliveryType::Pickup, BuyerType::Individual, 50_000_001_00, true)))->toBe('online_total')
        ->and($options->rejection(PaymentMethod::Payme, new PaymentContext(DeliveryType::Pickup, BuyerType::Individual, 100_00, false)))->toBe('ikpu');

    checkoutSettings(['require_ikpu_online' => false]);

    expect($options->rejection(PaymentMethod::Payme, new PaymentContext(DeliveryType::Pickup, BuyerType::Individual, 100_00, false)))->toBeNull();
});

it('validates STIR and PINFL per buyer type', function (BuyerType $type, string $tin, bool $valid) {
    expect(TaxId::isValid($type, TaxId::normalize($tin)))->toBe($valid);
})->with([
    'company 3…' => [BuyerType::Company, '301 234 567', true],
    'company 2…' => [BuyerType::Company, '201234567', true],
    'company 4…' => [BuyerType::Company, '401234567', false],
    'company short' => [BuyerType::Company, '30123456', false],
    'YaTT stir' => [BuyerType::SoleProprietor, '512345678', true],
    'YaTT pinfl' => [BuyerType::SoleProprietor, '31234567890123', true],
    'YaTT company stir' => [BuyerType::SoleProprietor, '301234567', false],
    'YaTT pinfl 7…' => [BuyerType::SoleProprietor, '71234567890123', false],
    'person none' => [BuyerType::Individual, '', true],
]);

it('quotes delivery: pickup and Kokand 0, region fee, or billed separately', function () {
    checkoutSettings(['region_fees' => ['samarkand' => 150_000]]);
    $settings = app(CheckoutSettings::class);

    expect(DeliveryQuote::for(DeliveryType::CourierKokand, null, $settings))->toEqual(new DeliveryQuote(DeliveryPricing::Fixed, 0))
        ->and(DeliveryQuote::for(DeliveryType::CourierRegion, Region::Samarkand, $settings))->toEqual(new DeliveryQuote(DeliveryPricing::Fixed, 150_000_00))
        ->and(DeliveryQuote::for(DeliveryType::CourierRegion, Region::Khorezm, $settings)->isSeparate())->toBeTrue();
});

it('re-validates stored checkout settings so a bad row never widens a cap', function () {
    checkoutSettings(['max_total' => 'lots', 'max_online_total' => 999_000_000_000, 'region_fees' => ['bukhara' => -5], 'delivery_ikpu' => '123']);
    $settings = app(CheckoutSettings::class);

    expect($settings->maxTotal())->toBe(1_000_000_000_00)
        ->and($settings->maxOnlineTotal())->toBe(1_000_000_000_00)
        ->and($settings->regionFee(Region::Bukhara))->toBeNull()
        ->and($settings->deliveryIkpu())->toBeNull();
});
