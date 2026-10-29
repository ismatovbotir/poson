<?php

use App\Actions\Orders\AddDeliveryCharge;
use App\Actions\Orders\CancelDeliveryCharge;
use App\Actions\Orders\MarkDeliveryChargePaid;
use App\Actions\Orders\MarkOrderPaid;
use App\Actions\Orders\RecordOrderRefund;
use App\Actions\Orders\SetPaymentReconciled;
use App\Actions\Orders\UpdateOrderDelivery;
use App\Actions\Orders\VoidManualPayment;
use App\Data\Orders\OrderDeliveryData;
use App\Enums\DeliveryPricing;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Jobs\SendTelegramMessage;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->sales = staffUser(Role::SalesManager);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
});

function errorKeyOf(callable $callback): ?string
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return array_key_first($exception->errors());
    }

    return null;
}

it('marks a bank transfer paid with the exact total, a date and a reference', function () {
    $order = Order::factory()->bankTransfer()->totalling(5_000_000_00)->create();

    $payment = app(MarkOrderPaid::class)->handle($order, manualPayment('bank_transfer', 5_000_000_00, 'PP 4471'), $this->sales);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($payment->amount)->toBe(5_000_000_00)
        ->and($payment->reference_key)->toBe('bank_transfer:PP4471')
        ->and($payment->recorded_by_id)->toBe($this->sales->id)
        ->and($order->fresh()->status)->toBe(OrderStatus::New);
});

it('rejects a wrong amount, a missing reference, a future date and another method', function (array $input, string $key) {
    $order = Order::factory()->uzqr()->totalling(1_000_000_00)->create();
    $data = manualPayment(...array_merge(['method' => 'uzqr_static', 'amount' => 1_000_000_00, 'reference' => 'TX-1'], $input));

    expect(errorKeyOf(fn () => app(MarkOrderPaid::class)->handle($order, $data, $this->sales)))->toBe($key)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);
})->with([
    'partial amount' => [['amount' => 999_999_00], 'amount'],
    'more than due' => [['amount' => 1_000_001_00], 'amount'],
    'no reference' => [['reference' => ''], 'reference'],
    'future date' => [['paidOn' => now('Asia/Tashkent')->addDay()->toDateString()], 'paid_on'],
    'bad date' => [['paidOn' => '2026-02-30'], 'paid_on'],
    'other method' => [['method' => 'bank_transfer'], 'method'],
]);

it('rejects a reference already used by another payment of the method', function () {
    OrderPayment::factory()->method(PaymentMethod::UzqrStatic)->create(['reference' => 'tx 77', 'reference_key' => OrderPayment::referenceKey(PaymentMethod::UzqrStatic, 'tx 77')]);
    $order = Order::factory()->uzqr()->totalling(100_00)->create();

    expect(errorKeyOf(fn () => app(MarkOrderPaid::class)->handle($order, manualPayment('uzqr_static', 100_00, 'TX77'), $this->sales)))->toBe('reference');
});

it('requires the channel for on receipt and never marks a webhook method by hand', function () {
    $cash = Order::factory()->totalling(100_00)->create();
    $payme = Order::factory()->totalling(100_00)->create(['payment_method' => PaymentMethod::Payme]);

    expect(errorKeyOf(fn () => app(MarkOrderPaid::class)->handle($cash, manualPayment('on_receipt', 100_00, null), $this->sales)))->toBe('receipt_channel')
        ->and(errorKeyOf(fn () => app(MarkOrderPaid::class)->handle($payme, manualPayment('payme', 100_00), $this->sales)))->toBe('method');

    app(MarkOrderPaid::class)->handle($cash, manualPayment('on_receipt', 100_00, null, channel: 'card'), $this->sales);
    expect($cash->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('alerts finance and sales on a manual payment with the reference masked, no buyer data', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('A', 35)]);
    Queue::fake([SendTelegramMessage::class]);
    $order = Order::factory()->bankTransfer()->totalling(100_00)->create(['name' => 'Secret Buyer', 'phone' => '+998901234567']);

    app(MarkOrderPaid::class)->handle($order, manualPayment('bank_transfer', 100_00, 'PAYORDER-98765'), $this->sales);

    Queue::assertPushed(SendTelegramMessage::class, 2);
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => in_array($job->chat, ['finance', 'sales'], true)
        && str_contains($job->html, '…8765')
        && ! str_contains($job->html, 'PAYORDER')
        && ! str_contains($job->html, 'Secret Buyer')
        && ! str_contains($job->html, '301234567')
        && str_contains($job->html, e($this->sales->name)));
});

it('bills delivery separately: only for separate delivery, within the cap, one pending at a time', function () {
    checkoutSettings(['max_delivery_charge' => 500_000]);
    $order = Order::factory()->separateDelivery()->totalling(1_000_000_00)->create();
    $fixed = Order::factory()->totalling(1_000_000_00)->create();

    expect(errorKeyOf(fn () => app(AddDeliveryCharge::class)->handle($fixed, 100_000_00, $this->sales)))->toBe('amount')
        ->and(errorKeyOf(fn () => app(AddDeliveryCharge::class)->handle($order, 600_000_00, $this->sales)))->toBe('amount')
        ->and(errorKeyOf(fn () => app(AddDeliveryCharge::class)->handle($order, 0, $this->sales)))->toBe('amount');

    $charge = app(AddDeliveryCharge::class)->handle($order, 150_000_00, $this->sales);

    expect(errorKeyOf(fn () => app(AddDeliveryCharge::class)->handle($order, 10_000_00, $this->sales)))->toBe('amount')
        ->and($order->fresh()->total)->toBe(1_000_000_00)
        ->and($charge->status)->toBe(OrderPaymentStatus::Pending);

    expect(errorKeyOf(fn () => app(MarkDeliveryChargePaid::class)->handle($order, $charge, manualPayment('payme', 150_000_00), $this->sales)))->toBe('method')
        ->and(errorKeyOf(fn () => app(MarkDeliveryChargePaid::class)->handle($order, $charge, manualPayment('bank_transfer', 140_000_00), $this->sales)))->toBe('amount');

    app(MarkDeliveryChargePaid::class)->handle($order, $charge, manualPayment('bank_transfer', 150_000_00, 'DLV-1'), $this->sales);

    expect($charge->fresh()->status)->toBe(OrderPaymentStatus::Paid)->and($order->fresh()->total)->toBe(1_000_000_00);
});

it('cancels a pending charge only with a reason', function () {
    $order = Order::factory()->separateDelivery()->create();
    $charge = OrderPayment::factory()->deliveryCharge()->for($order)->create();

    expect(errorKeyOf(fn () => app(CancelDeliveryCharge::class)->handle($order, $charge, ' ', $this->sales)))->toBe('reason');

    app(CancelDeliveryCharge::class)->handle($order, $charge, 'Customer picks it up', $this->sales);

    expect($charge->fresh()->status)->toBe(OrderPaymentStatus::Cancelled)->and(OrderPayment::query()->count())->toBe(1);
});

it('turns a paid order whose delivery changes into a separate delivery charge, total unchanged', function () {
    $order = Order::factory()->uzqr()->paid()->totalling(1_000_000_00)->status(OrderStatus::Confirmed)->create();

    app(UpdateOrderDelivery::class)->handle($order, new OrderDeliveryData('courier_region', 'khorezm', 'Urganch, 5'), $this->sales);

    expect($order->fresh()->delivery_pricing)->toBe(DeliveryPricing::Separate)
        ->and($order->fresh()->total)->toBe(1_000_000_00);

    app(AddDeliveryCharge::class)->handle($order, 200_000_00, $this->sales);
    expect($order->fresh()->total)->toBe(1_000_000_00);
});

it('voids a manual payment (Admin policy), keeps the row and frees the reference', function () {
    $order = Order::factory()->bankTransfer()->totalling(100_00)->create();
    $payment = app(MarkOrderPaid::class)->handle($order, manualPayment('bank_transfer', 100_00, 'REF-1'), $this->sales);

    expect($this->sales->can('voidPayment', $order))->toBeFalse()
        ->and($this->admin->can('voidPayment', $order))->toBeTrue()
        ->and(errorKeyOf(fn () => app(VoidManualPayment::class)->handle($order, $payment, '', $this->admin)))->toBe('reason');

    app(VoidManualPayment::class)->handle($order, $payment, 'Wrong order', $this->admin);

    expect($payment->fresh()->status)->toBe(OrderPaymentStatus::Cancelled)
        ->and($payment->fresh()->reference_key)->toBeNull()
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Unpaid);

    // The reference can be used again for the right payment.
    app(MarkOrderPaid::class)->handle($order, manualPayment('bank_transfer', 100_00, 'REF-1'), $this->sales);
    expect(OrderPayment::query()->count())->toBe(2);
});

it('refuses to void after shipping, and turns a voided delivery charge back into a pending one', function () {
    $shipped = Order::factory()->bankTransfer()->paid()->status(OrderStatus::Shipped)->create();
    $paid = OrderPayment::factory()->for($shipped)->create();

    expect(errorKeyOf(fn () => app(VoidManualPayment::class)->handle($shipped, $paid, 'oops', $this->admin)))->toBe('reason');

    $order = Order::factory()->separateDelivery()->paid()->create();
    $charge = OrderPayment::factory()->for($order)->create(['purpose' => OrderPaymentPurpose::Delivery, 'amount' => 50_000_00]);
    app(VoidManualPayment::class)->handle($order, $charge, 'Wrong amount', $this->admin);

    expect($order->payments()->where('status', 'pending')->value('amount'))->toBe(50_000_00)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid);
});

it('records a refund (Admin only), cancels the order, never for Payme', function () {
    $order = Order::factory()->bankTransfer()->paid()->totalling(100_00)->status(OrderStatus::Confirmed)->create();
    $payment = OrderPayment::factory()->for($order)->create(['amount' => 100_00]);
    $payme = Order::factory()->paid()->create(['payment_method' => PaymentMethod::Payme]);
    $paymePayment = OrderPayment::factory()->for($payme)->create(['method' => PaymentMethod::Payme, 'reference_key' => null, 'provider' => 'payme', 'provider_ref' => 'abc']);

    expect($this->sales->can('refund', $order))->toBeFalse()
        ->and(errorKeyOf(fn () => app(RecordOrderRefund::class)->handle($payme, $paymePayment, 'Customer asked', $this->admin)))->toBe('reason');

    app(RecordOrderRefund::class)->handle($order, $payment, 'Customer asked', $this->admin);

    expect($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(OrderPaymentStatus::Refunded);
});

it('lets only an Admin tick manual payments as reconciled, logged', function () {
    $payment = OrderPayment::factory()->create();

    expect($this->sales->can('reconcile', $payment))->toBeFalse()->and($this->admin->can('reconcile', $payment))->toBeTrue();

    app(SetPaymentReconciled::class)->handle($payment, true, $this->admin);

    expect($payment->fresh()->reconciled_by_id)->toBe($this->admin->id)
        ->and(OrderPayment::query()->unreconciled()->count())->toBe(0)
        ->and($payment->order->activities()->where('meta->action', 'reconciled')->exists())->toBeTrue();
});
