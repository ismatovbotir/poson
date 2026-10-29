<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Enums\ActivityType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancel a pending delivery charge (security condition 23): orders.update,
 * a reason is required; the row is kept as cancelled. A paid charge can
 * only be voided (VoidManualPayment, Admin).
 */
class CancelDeliveryCharge
{
    use LocksOrder;

    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, OrderPayment $charge, string $reason, User $actor): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('orders.errors.reason_required')]);
        }

        DB::transaction(function () use ($order, $charge, $reason, $actor): void {
            $order = $this->lockOrder($order);
            $charge = OrderPayment::query()->whereKey($charge->id)->where('order_id', $order->id)->lockForUpdate()->first();

            if (! $charge instanceof OrderPayment || $charge->purpose !== OrderPaymentPurpose::Delivery || $charge->status !== OrderPaymentStatus::Pending) {
                throw ValidationException::withMessages(['reason' => __('orders.errors.charge_not_pending')]);
            }

            $charge->forceFill([
                'status' => OrderPaymentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_id' => $actor->id,
                'cancel_reason' => mb_substr($reason, 0, 500),
            ])->save();

            $this->log->handle($order, 'delivery_charge_cancelled', $actor, ['payment_id' => $charge->id, 'amount' => $charge->amount], ActivityType::Payment, $reason);
        });
    }
}
