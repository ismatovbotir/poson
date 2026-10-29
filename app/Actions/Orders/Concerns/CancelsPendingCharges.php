<?php

namespace App\Actions\Orders\Concerns;

use App\Actions\Orders\LogOrderActivity;
use App\Enums\ActivityType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;

/**
 * Cancel an order's pending delivery charges (security review M1): a
 * cancelled or refunded order must not ask for delivery money any more.
 * Runs inside the caller's transaction, under the order lock; each charge
 * keeps its row (cancelled, with the reason) and gets a history entry.
 */
trait CancelsPendingCharges
{
    protected function cancelPendingCharges(Order $order, string $reason, ?User $actor, LogOrderActivity $log): void
    {
        $charges = OrderPayment::query()
            ->where('order_id', $order->id)
            ->where('purpose', OrderPaymentPurpose::Delivery->value)
            ->where('status', OrderPaymentStatus::Pending->value)
            ->lockForUpdate()
            ->get();

        foreach ($charges as $charge) {
            $charge->forceFill([
                'status' => OrderPaymentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_id' => $actor?->id,
                'cancel_reason' => mb_substr($reason, 0, 500),
            ])->save();

            $log->handle($order, 'delivery_charge_cancelled', $actor, ['payment_id' => $charge->id, 'amount' => $charge->amount], ActivityType::Payment, $reason);
        }
    }
}
