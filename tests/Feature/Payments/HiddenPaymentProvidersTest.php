<?php

use App\Actions\Orders\ChangeOrderPaymentMethod;
use App\Actions\Orders\CreateManualOrder;
use App\Actions\Orders\MarkOrderPaid;
use App\Actions\Orders\PlaceOrder;
use App\Actions\Orders\RecordOrderRefund;
use App\Actions\Orders\UpdateOrder;
use App\Data\Orders\ManualOrderData;
use App\Data\Orders\OrderDeliveryData;
use App\Data\Orders\OrderDetailsData;
use App\Data\Orders\OrderLineData;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show as OrderShow;
use App\Livewire\Admin\Payments\Index as PaymentsIndex;
use App\Livewire\Admin\Settings\Checkout;
use App\Livewire\Admin\Settings\Integrations;
use App\Models\IntegrationSecret;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Support\CompanySettings;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use App\Support\Telegram\OrderMessage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Uzqr;

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

it('applies the custom online cap to dynamic UzQR: exact cap ok, one tiyin over and zero rejected, PlaceOrder refuses above it', function () {
    Uzqr::configure();
    checkoutSettings(['max_online_total' => 30_000_000]);
    $options = app(PaymentOptions::class);
    $live = fn (int $total) => new PaymentContext(DeliveryType::Pickup, BuyerType::Individual, $total, false);

    $result = [
        $options->rejection(PaymentMethod::UzqrDynamic, $live(30_000_000_00)),
        $options->rejection(PaymentMethod::UzqrDynamic, $live(30_000_000_01)),
        $options->rejection(PaymentMethod::UzqrDynamic, $live(0)),
    ];

    expect($result[1])->toBe('online_total')
        ->and($result[2])->toBe('online_total')
        ->and($result[0])->toBeNull();

    $cart = orderCart(Product::factory()->withIkpu()->create(['price' => 30_000_001_00]));

    expect(fn () => app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, ['paymentMethod' => 'uzqr_dynamic'])))->toThrow(ValidationException::class)
        ->and(Order::query()->count())->toBe(0);
});

it('has an unmocked providerReady false for Payme without a route', function () {
    expect(app(PaymentOptions::class)->providerReady(PaymentMethod::Payme, false))->toBeFalse();
});

it('ignores tampered Payme values sent with save of another section', function () {
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(Integrations::class)
        ->set('form.values.payme.mode', 'prod')
        ->set('form.values.payme.key', 'fake-tampered-key-0000000')
        ->set('secretInputs.payme.key', 'fake-tampered-secret-0000000')
        ->call('save', 'uzqr');

    expect(Setting::query()->where('key', 'like', 'payme.%')->count())->toBe(0)
        ->and(IntegrationSecret::query()->where('key', 'like', 'payme.%')->count())->toBe(0);
});

it('refuses manual mark-paid on an old unpaid Payme order', function (string $method) {
    $order = Order::factory()->totalling(100_00)->create(['payment_method' => PaymentMethod::Payme]);

    expect(fn () => app(MarkOrderPaid::class)->handle($order, manualPayment($method, 100_00, 'REF-1', null, $method === 'on_receipt' ? 'cash' : null), staffUser(Role::SalesManager)))->toThrow(ValidationException::class)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
})->with(['payme', 'on_receipt']);

it('shows no pay button or QR on the public page of an old unpaid Payme order', function () {
    $order = Order::factory()->totalling(100_00)->create(['payment_method' => PaymentMethod::Payme]);

    $this->get(route('orders.public', $order->ulid))->assertOk()
        ->assertDontSeeHtml('data-pay-button')
        ->assertDontSeeHtml('data-uzqr')
        ->assertSeeHtml('data-online-unavailable');
});

it('renders old Payme and Click rows with their labels in admin, Telegram and MCP', function () {
    $admin = staffUser();
    $payme = Order::factory()->paid()->totalling(100_00)->create(['payment_method' => PaymentMethod::Payme]);
    $click = Order::factory()->paid()->totalling(200_00)->create(['payment_method' => PaymentMethod::Click]);
    OrderPayment::factory()->for($payme)->create(['method' => PaymentMethod::Payme, 'reference_key' => null, 'provider' => 'payme', 'provider_ref' => 'a1']);
    $clickPayment = OrderPayment::factory()->for($click)->create(['method' => PaymentMethod::Click, 'reference_key' => null, 'provider' => 'click', 'provider_ref' => 'c1', 'status' => OrderPaymentStatus::Refunded, 'refunded_at' => now(), 'paid_at' => now()]);

    Livewire::actingAs($admin)->test(OrdersIndex::class)->assertSee($payme->number)->assertSee($click->number)->assertSee('Payme')->assertSee('Click');
    Livewire::actingAs($admin)->test(PaymentsIndex::class)->assertSee('Payme')->assertSee('Click');
    Livewire::actingAs($admin)->test(OrderShow::class, ['order' => $payme])->assertSee('Payme');

    foreach (['uz', 'ru', 'en'] as $locale) {
        $text = OrderMessage::placed($payme, $locale)->html;
        expect($text)->toContain('Payme');
    }

    mcpHolder($plain = fakeMcpTokenValue());
    mcpTelegramConfigured();

    $stats = mcpCallTool($plain, 'get_order_stats', ['days' => 7, 'group_by' => 'payment_method'])->assertOk()->json('result.structuredContent');
    $rows = collect($stats['rows'])->keyBy('key');

    expect($rows->keys()->all())->toContain('payme', 'click')
        ->and($rows['payme']['label'])->toBe('Payme')
        ->and($rows['click']['label'])->toBe('Click');

    $attention = mcpCallTool($plain, 'get_payments_attention')->assertOk()->json('result.structuredContent');

    expect(collect($attention['groups']['refunds'])->pluck('method')->all())->toContain('click');
});

it('refunds an old paid Click row and refuses the Payme one', function () {
    $admin = staffUser();
    $click = Order::factory()->paid()->totalling(100_00)->status(OrderStatus::Confirmed)->create(['payment_method' => PaymentMethod::Click]);
    $clickPayment = OrderPayment::factory()->for($click)->create(['method' => PaymentMethod::Click, 'reference_key' => null, 'amount' => 100_00]);
    $payme = Order::factory()->paid()->status(OrderStatus::Confirmed)->create(['payment_method' => PaymentMethod::Payme]);
    $paymePayment = OrderPayment::factory()->for($payme)->create(['method' => PaymentMethod::Payme, 'reference_key' => null, 'provider' => 'payme', 'provider_ref' => 'abc']);

    expect(fn () => app(RecordOrderRefund::class)->handle($payme, $paymePayment, 'Customer asked', $admin))->toThrow(ValidationException::class, __('orders.errors.refund_payme'));

    app(RecordOrderRefund::class)->handle($click, $clickPayment, 'Customer asked', $admin);

    expect($clickPayment->fresh()->status)->toBe(OrderPaymentStatus::Refunded)
        ->and($click->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($paymePayment->fresh()->status)->toBe(OrderPaymentStatus::Paid);
});

it('refuses line changes on an old unpaid Payme order while it keeps the method, and saves them once moved to an allowed method', function () {
    $sales = staffUser(Role::SalesManager);
    $product = Product::factory()->withIkpu()->create(['price' => 100_00]);
    $order = app(CreateManualOrder::class)->handle(new ManualOrderData(
        details: new OrderDetailsData('Aziz', '+998901234567', 'individual'),
        delivery: new OrderDeliveryData('pickup'),
        paymentMethod: 'on_receipt',
        lines: [new OrderLineData(productId: $product->id, qty: 1)],
    ), $sales);
    $order->forceFill(['payment_method' => PaymentMethod::Payme])->save();
    $item = $order->items()->first();
    $details = new OrderDetailsData('Aziz', '+998901234567', 'individual');
    $update = fn (string $method) => app(UpdateOrder::class)->handle($order->fresh(), $details, null, new OrderDeliveryData('pickup'), [new OrderLineData(itemId: $item->id, qty: 3)], $method, $sales);

    expect(fn () => $update('payme'))->toThrow(ValidationException::class)
        ->and($item->fresh()->qty)->toBe(1)
        ->and($order->fresh()->payment_method)->toBe(PaymentMethod::Payme);

    $update('on_receipt');

    expect($item->fresh()->qty)->toBe(3)
        ->and($order->fresh()->payment_method)->toBe(PaymentMethod::OnReceipt);
});
