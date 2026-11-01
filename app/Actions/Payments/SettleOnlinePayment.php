<?php

namespace App\Actions\Payments;

use App\Actions\Orders\LogOrderActivity;
use App\Enums\ActivityType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\Orders\OrderPaid;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderPayment;
use LogicException;

/**
 * Mark an order's goods total paid from a verified provider confirmation
 * (shared by every online provider: dynamic UzQR status 2 in 11e; Payme
 * PerformTransaction and Click complete in 11c / 11d). Runs inside the
 * caller's DB transaction with the order already locked (security
 * condition 3), after the caller checked its transaction and the amount.
 *
 * Writes a paid goods row in order_payments (provider + provider_ref,
 * unique: a second settle of the same provider payment fails), sets the
 * order paid, logs it and alerts finance + sales (OrderPaid, after commit;
 * no payer data). The order status never changes here.
 */
class SettleOnlinePayment
{
    public function __construct(protected LogOrderActivity $log) {}

    public function handle(Order $order, PaymentMethod $method, string $provider, string $providerRef, int $amount): OrderPayment
    {
        if (! $method->isOnline() || $order->payment_method !== $method || $amount !== $order->total || $amount <= 0) {
            throw new LogicException('An online payment must use the order’s online method and its exact total.');
        }

        $payment = OrderPayment::query()->create([
            'order_id' => $order->id,
            'purpose' => OrderPaymentPurpose::Goods,
            'method' => $method,
            'status' => OrderPaymentStatus::Paid,
            'amount' => $amount,
            'is_test' => $order->is_test,
            'provider' => $provider,
            'provider_ref' => $providerRef,
            'paid_on' => now(Lead::TIMEZONE)->toDateString(),
            'paid_at' => now(),
        ]);

        $order->forceFill(['payment_status' => PaymentStatus::Paid, 'paid_at' => now(), 'payment_pending_at' => null])->save();

        $this->log->handle($order, 'paid', null, [
            'payment_id' => $payment->id,
            'method' => $method->value,
            'amount' => $amount,
            'provider' => $provider,
        ], ActivityType::Payment);

        OrderPaid::dispatch($order, $payment);

        return $payment;
    }
}
