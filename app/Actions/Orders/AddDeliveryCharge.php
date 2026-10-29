<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Enums\ActivityType;
use App\Enums\DeliveryPricing;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Support\Orders\CheckoutSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bill delivery separately (plan 11 v2 §1.2, security condition 23):
 * orders.update, only while the order's delivery is "separate" (a region
 * without a fee, or the delivery changed after payment), before completed
 * and not cancelled, at most one pending charge per order, 1 tiyin ..
 * checkout.max_delivery_charge. The order total never changes: the charge
 * is its own pending order_payments row, paid later by hand
 * (MarkDeliveryChargePaid) and shown on /o.
 */
class AddDeliveryCharge
{
    use LocksOrder;

    public function __construct(protected CheckoutSettings $settings, protected LogOrderActivity $log) {}

    /**
     * @param  int|null  $amount  tiyin
     *
     * @throws ValidationException
     */
    public function handle(Order $order, ?int $amount, User $actor, ?string $note = null): OrderPayment
    {
        return DB::transaction(function () use ($order, $amount, $actor, $note): OrderPayment {
            $order = $this->lockOrder($order);

            if ($order->status->isClosed()) {
                throw ValidationException::withMessages(['amount' => __('orders.errors.closed')]);
            }

            if ($order->delivery_pricing !== DeliveryPricing::Separate) {
                throw ValidationException::withMessages(['amount' => __('orders.errors.delivery_not_separate')]);
            }

            if ($amount === null || $amount < 1 || $amount > $this->settings->maxDeliveryCharge()) {
                throw ValidationException::withMessages(['amount' => __('orders.errors.delivery_amount')]);
            }

            $pending = OrderPayment::query()
                ->where('order_id', $order->id)
                ->where('purpose', OrderPaymentPurpose::Delivery->value)
                ->where('status', OrderPaymentStatus::Pending->value)
                ->exists();

            if ($pending) {
                throw ValidationException::withMessages(['amount' => __('orders.errors.delivery_pending_exists')]);
            }

            $note = $note !== null ? trim($note) : '';

            $charge = OrderPayment::query()->create([
                'order_id' => $order->id,
                'purpose' => OrderPaymentPurpose::Delivery,
                'status' => OrderPaymentStatus::Pending,
                'amount' => $amount,
                'is_test' => $order->is_test,
                'note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
                'created_by_id' => $actor->id,
            ]);

            $this->log->handle($order, 'delivery_charge_added', $actor, ['payment_id' => $charge->id, 'amount' => $amount], ActivityType::Payment);

            return $charge;
        });
    }
}
