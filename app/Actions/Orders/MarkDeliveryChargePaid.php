<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Actions\Orders\Concerns\RecordsManualPayment;
use App\Data\Orders\ManualPaymentData;
use App\Enums\ActivityType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Events\Orders\DeliveryChargePaid;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mark a pending delivery charge paid (security conditions 20, 21, 23):
 * orders.mark-paid, a manual method only (online payment of delivery is a
 * later step), exactly the charge amount, a paid-on date not in the future,
 * a reference unique per method for uzqr_static / bank_transfer, before the
 * order is completed. Alerts the finance chat.
 */
class MarkDeliveryChargePaid
{
    use LocksOrder, RecordsManualPayment;

    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, OrderPayment $charge, ManualPaymentData $data, User $actor): OrderPayment
    {
        try {
            $charge = DB::transaction(function () use ($order, $charge, $data, $actor): OrderPayment {
                $order = $this->lockOrder($order);
                $charge = OrderPayment::query()->whereKey($charge->id)->where('order_id', $order->id)->lockForUpdate()->first();

                if (! $charge instanceof OrderPayment || $charge->purpose !== OrderPaymentPurpose::Delivery || $charge->status !== OrderPaymentStatus::Pending) {
                    throw ValidationException::withMessages(['payment' => __('orders.errors.charge_not_pending')]);
                }

                if ($order->status->isClosed()) {
                    throw ValidationException::withMessages(['payment' => __('orders.errors.closed')]);
                }

                $checked = $this->checkManualPayment($data, $charge->amount);

                $charge->forceFill([
                    'method' => $checked['method'],
                    'receipt_channel' => $checked['receipt_channel'],
                    'status' => OrderPaymentStatus::Paid,
                    'reference' => $checked['reference'],
                    'reference_key' => $checked['reference_key'],
                    'note' => $checked['note'] ?? $charge->note,
                    'paid_on' => $checked['paid_on'],
                    'paid_at' => now(),
                    'recorded_by_id' => $actor->id,
                ])->save();

                $this->log->handle($order, 'delivery_charge_paid', $actor, [
                    'payment_id' => $charge->id,
                    'method' => $checked['method']->value,
                    'amount' => $charge->amount,
                    'reference' => OrderPayment::maskReference($charge->reference),
                    'paid_on' => $checked['paid_on'],
                ], ActivityType::Payment);

                return $charge;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => __('orders.errors.reference_used')]);
        }

        DeliveryChargePaid::dispatch($order->refresh(), $charge, $actor);

        return $charge;
    }
}
