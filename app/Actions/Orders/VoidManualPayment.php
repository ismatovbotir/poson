<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Enums\ActivityType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use App\Events\Orders\ManualPaymentVoided;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Void a manual payment recorded by mistake (security condition 22):
 * Admin only (OrderPolicy::voidPayment, authorized by the caller), a reason
 * is required, only before the order is shipped and only for manual methods
 * (webhook payments are cancelled by their provider). The row becomes
 * cancelled (never deleted) and frees its reference; a goods payment sets
 * the order back to unpaid, a delivery charge gets a new pending charge of
 * the same amount. Logged and alerted to the finance chat.
 */
class VoidManualPayment
{
    use LocksOrder;

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

            if ($order->hasActiveTransaction()) {
                throw ValidationException::withMessages(['reason' => __('orders.errors.lines_locked')]);
            }

            if (! $payment->isManual()) {
                throw ValidationException::withMessages(['reason' => __('orders.errors.void_webhook')]);
            }

            if ($order->status->isShippedOrLater()) {
                throw ValidationException::withMessages(['reason' => __('orders.errors.void_after_shipped')]);
            }

            $payment->forceFill([
                'status' => OrderPaymentStatus::Cancelled,
                'reference_key' => null,
                'cancelled_at' => now(),
                'cancelled_by_id' => $actor->id,
                'cancel_reason' => mb_substr($reason, 0, 500),
            ])->save();

            if ($payment->purpose === OrderPaymentPurpose::Goods) {
                $order->forceFill(['payment_status' => PaymentStatus::Unpaid, 'paid_at' => null])->save();
            } else {
                OrderPayment::query()->create([
                    'order_id' => $order->id,
                    'purpose' => OrderPaymentPurpose::Delivery,
                    'status' => OrderPaymentStatus::Pending,
                    'amount' => $payment->amount,
                    'is_test' => $order->is_test,
                    'created_by_id' => $actor->id,
                ]);
            }

            $this->log->handle($order, 'payment_voided', $actor, [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose->value,
                'method' => $payment->method?->value,
                'amount' => $payment->amount,
            ], ActivityType::Payment, $reason);

            return $payment;
        });

        ManualPaymentVoided::dispatch($order->refresh(), $payment, $actor);
    }
}
