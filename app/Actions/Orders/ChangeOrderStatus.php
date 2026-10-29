<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\CancelsPendingCharges;
use App\Actions\Orders\Concerns\LocksOrder;
use App\Enums\DeliveryType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move an order along its state machine (plan 11 v2 §1.6): orders.update,
 * one step forward (confirmed may skip processing) or cancel.
 *
 * - cancel: from new / confirmed / processing, only while the goods are
 *   unpaid or refunded and no online transaction holds the order (paid
 *   money is refunded first, condition 3);
 * - shipped: needs payment unless paid on receipt; a Kokand courier needs
 *   the address confirmed on the call;
 * - completed: needs payment and no pending delivery charge.
 * Payment never changes the status by itself.
 */
class ChangeOrderStatus
{
    use CancelsPendingCharges, LocksOrder;

    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, OrderStatus $to, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $to, $actor, $reason): void {
            $order = $this->lockOrder($order);
            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => __('orders.errors.transition', ['from' => $from->label(), 'to' => $to->label()])]);
            }

            if ($to === OrderStatus::Cancelled && (! in_array($order->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Refunded], true) || $order->hasActiveTransaction())) {
                throw ValidationException::withMessages(['status' => __('orders.errors.cancel_paid')]);
            }

            if ($to === OrderStatus::Shipped) {
                if ($order->payment_status !== PaymentStatus::Paid && $order->payment_method->isPrepaid()) {
                    throw ValidationException::withMessages(['status' => __('orders.errors.ship_unpaid')]);
                }

                if ($order->delivery_type === DeliveryType::CourierKokand && ! $order->kokand_address_confirmed) {
                    throw ValidationException::withMessages(['status' => __('orders.errors.kokand_unconfirmed')]);
                }
            }

            if ($to === OrderStatus::Completed) {
                if ($order->payment_status !== PaymentStatus::Paid) {
                    throw ValidationException::withMessages(['status' => __('orders.errors.complete_unpaid')]);
                }

                $pendingCharge = OrderPayment::query()
                    ->where('order_id', $order->id)
                    ->where('purpose', OrderPaymentPurpose::Delivery->value)
                    ->where('status', OrderPaymentStatus::Pending->value)
                    ->exists();

                if ($pendingCharge) {
                    throw ValidationException::withMessages(['status' => __('orders.errors.complete_pending_charge')]);
                }
            }

            $stamp = match ($to) {
                OrderStatus::Confirmed => 'confirmed_at',
                OrderStatus::Shipped => 'shipped_at',
                OrderStatus::Completed => 'completed_at',
                OrderStatus::Cancelled => 'cancelled_at',
                default => null,
            };

            $reason = $reason !== null ? trim($reason) : '';

            $attributes = ['status' => $to, 'status_changed_at' => now()];

            if ($stamp !== null) {
                $attributes[$stamp] = now();
            }

            if ($to === OrderStatus::Cancelled && $reason !== '') {
                $attributes['cancel_reason'] = mb_substr($reason, 0, 500);
            }

            $order->forceFill($attributes)->save();

            if ($to === OrderStatus::Cancelled) {
                $this->cancelPendingCharges($order, $reason !== '' ? $reason : __('orders.charge_cancelled_with_order'), $actor, $this->log);
            }

            $this->log->handle($order, 'status', $actor, ['from' => $from->value, 'to' => $to->value], body: $reason !== '' ? $reason : null);
        });
    }
}
