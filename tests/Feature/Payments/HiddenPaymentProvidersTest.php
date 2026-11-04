<?php

use App\Actions\Orders\ChangeOrderPaymentMethod;
use App\Actions\Orders\CreateManualOrder;
use App\Actions\Orders\PlaceOrder;
use App\Data\Orders\ManualOrderData;
use App\Data\Orders\OrderDeliveryData;
use App\Data\Orders\OrderDetailsData;
use App\Data\Orders\OrderLineData;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Payments\Index as PaymentsIndex;
use App\Livewire\Admin\Settings\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Support\CompanySettings;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\PaymentOptions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Plan 23: Payme and Click are hidden (not deleted). The enum cases stay so
 * old orders render; every new-order path refuses them.
 */
beforeEach(function () {
    $this->hiddenProvidersOptions = Mockery::mock(PaymentOptions::class, [app(CheckoutSettings::class), app(CompanySettings::class), app(UzqrConfig::class)])->makePartial();
    $this->hiddenProvidersOptions->shouldReceive('providerReady')->andReturn(true);
    config(['app.url' => 'https://poson.uz']);
    freshSecrets(['services.payme.mode' => 'prod', 'services.payme.merchant_id' => '587f72c72cac0d162c722ae2', 'services.payme.key' => 'fake-payme-live-key-0000']);
});

it('marks exactly Payme and Click as reserved and keeps the cases', function () {
    expect(PaymentMethod::Payme->isReserved())->toBeTrue()
        ->and(PaymentMethod::Click->isReserved())->toBeTrue()
        ->and(PaymentMethod::available())->not->toContain(PaymentMethod::Payme, PaymentMethod::Click)
        ->and(PaymentMethod::available())->toContain(PaymentMethod::UzqrDynamic, PaymentMethod::UzqrStatic, PaymentMethod::OnReceipt, PaymentMethod::BankTransfer)
        ->and(PaymentMethod::from('payme')->label())->toBe(__('enums.payment_method.payme'));
});

it('rejects a reserved method in PlaceOrder even when route, hold and keys say ready', function (string $method) {
    app()->instance(PaymentOptions::class, $this->hiddenProvidersOptions);
    $cart = orderCart();

    expect(fn () => app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, ['paymentMethod' => $method])))->toThrow(ValidationException::class)
        ->and(Order::query()->count())->toBe(0);
})->with(['payme', 'click']);

it('rejects a reserved method in CreateManualOrder', function (string $method) {
    app()->instance(PaymentOptions::class, $this->hiddenProvidersOptions);
    $product = Product::factory()->withIkpu()->create(['price' => 100_00]);

    expect(fn () => app(CreateManualOrder::class)->handle(new ManualOrderData(
        details: new OrderDetailsData('Aziz', '+998901234567', 'individual'),
        delivery: new OrderDeliveryData('pickup'),
        paymentMethod: $method,
        lines: [new OrderLineData(productId: $product->id)],
    ), staffUser(Role::SalesManager)))->toThrow(ValidationException::class)
        ->and(Order::query()->count())->toBe(0);
})->with(['payme', 'click']);

it('rejects switching an order to a reserved method', function (string $method) {
    app()->instance(PaymentOptions::class, $this->hiddenProvidersOptions);
    $order = Order::factory()->totalling(100_00)->create();

    expect(fn () => app(ChangeOrderPaymentMethod::class)->handle($order, $method, staffUser(Role::SalesManager)))->toThrow(ValidationException::class)
        ->and($order->fresh()->payment_method)->toBe($order->payment_method);
})->with(['payme', 'click']);

it('re-saves an old Payme order unchanged and lets it move to an allowed method', function () {
    $sales = staffUser(Role::SalesManager);
    $order = Order::factory()->totalling(100_00)->create(['payment_method' => PaymentMethod::Payme]);
    $activities = $order->activities()->count();

    app(ChangeOrderPaymentMethod::class)->handle($order, 'payme', $sales);

    expect($order->fresh()->payment_method)->toBe(PaymentMethod::Payme)
        ->and($order->activities()->count())->toBe($activities);

    app(ChangeOrderPaymentMethod::class)->handle($order, 'on_receipt', $sales);

    expect($order->fresh()->payment_method)->toBe(PaymentMethod::OnReceipt);
});

it('lists neither provider in the orders and payments filters, but an old ?method= link still renders', function () {
    $admin = staffUser();
    Order::factory()->create(['payment_method' => PaymentMethod::Payme]);

    foreach ([OrdersIndex::class, PaymentsIndex::class] as $screen) {
        Livewire::actingAs($admin)->test($screen)
            ->assertDontSeeHtml('value="payme"')
            ->assertDontSeeHtml('value="click"')
            ->assertSeeHtml('value="uzqr_static"');

        Livewire::actingAs($admin)->withQueryParams(['method' => 'payme'])->test($screen)->assertOk();
    }
});

it('keeps a stored require_ikpu_online false through a Settings → Checkout save without the toggle', function () {
    confirmAdminPassword();
    checkoutSettings(['require_ikpu_online' => false]);

    $component = Livewire::actingAs(staffUser())->test(Checkout::class)->assertDontSeeHtml('form.require_ikpu_online');
    $component->set('form.max_total', '900 000 000')->call('save')->assertHasNoErrors();

    expect(Setting::query()->where('key', 'checkout')->value('value')['require_ikpu_online'])->toBeFalse()
        ->and(app(CheckoutSettings::class)->requireIkpuOnline())->toBeFalse();
});
