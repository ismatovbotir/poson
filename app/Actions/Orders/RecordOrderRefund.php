<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\CancelsPendingCharges;
use App\Actions\Orders\Concerns\LocksOrder;
use App\Enums\ActivityType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\Orders\OrderRefunded;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Record a refund paid back outside the site (plan 11 v2 §1.6, security
 * conditions 18 and 23): orders.refund (Admin, authorized by the caller),
 * a reason is required, the full amount of one paid row; never for Payme
 * (Payme refunds only through the Payme cabinet → CancelTransaction).
 *
 * - goods: only while the order is refundable (paid, not shipped yet or
 *   cancelled); the order becomes refunded and, unless shipped, cancelled;
 * - a delivery charge: its row only (the order is unchanged).
 *
 * Logged; alerts finance + sales.
 */
class RecordOrderRefund
{
    use CancelsPendingCharges, LocksOrder;

    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, OrderPayment $payment, string $reason, User $actor): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('orders.errors.reason_required')]);
        }

        $payment = DB::transaction(function () use ($order, $payment, $reason, $actor): OrderPayment {
            $order = $this->lockOrder($order);
            $payment = OrderPayment::query()->whereKey($payment->id)->where('order_id', $order->id)->lockForUpdate()->first();

            if (! $payment instanceof OrderPayment || $payment->status !== OrderPaymentStatus::Paid) {
                throw ValidationException::withMessages(['reason' => __('orders.errors.payment_not_paid')]);
            }

            if ($payment->method === PaymentMethod::Payme) {
                throw ValidationException::withMessages(['reason' => __('orders.errors.refund_payme')]);
            }

            if ($payment->purpose === OrderPaymentPurpose::Goods && ! $order->isRefundable()) {
                throw ValidationException::withMessages(['reason' => __('orders.errors.not_refundable')]);
            }

            $payment->forceFill([
                'status' => OrderPaymentStatus::Refunded,
                'refunded_at' => now(),
                'refunded_by_id' => $actor->id,
                'refund_reason' => mb_substr($reason, 0, 500),
            ])->save();

            if ($payment->purpose === OrderPaymentPurpose::Goods) {
                $cancel = in_array($order->status, [OrderStatus::New, OrderStatus::Confirmed, OrderStatus::Processing], true);

                $order->forceFill([
                    'payment_status' => PaymentStatus::Refunded,
                    'refunded_at' => now(),
                    ...($cancel ? ['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'status_changed_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 500)] : []),
                ])->save();

                // Nothing more is asked of a refunded order (security review M1).
                $this->cancelPendingCharges($order, $reason, $actor, $this->log);
            }

            $this->log->handle($order, 'refunded', $actor, [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose->value,
                'method' => $payment->method?->value,
                'amount' => $payment->amount,
            ], ActivityType::Payment, $reason);

            return $payment;
        });

        OrderRefunded::dispatch($order->refresh(), $payment, $actor);
    }
}
