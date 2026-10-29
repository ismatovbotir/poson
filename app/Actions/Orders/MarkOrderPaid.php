<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Actions\Orders\Concerns\RecordsManualPayment;
use App\Data\Orders\ManualPaymentData;
use App\Enums\ActivityType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\Orders\OrderPaid;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mark an order's goods total paid by hand (plan 11 v2 §1.6, security
 * conditions 3, 20, 21, 27): orders.mark-paid (Sales + Admin, authorized by
 * the caller), only for the order's own manual method (uzqr_static,
 * on_receipt, bank_transfer; webhook methods are paid only by their
 * webhooks), unpaid, no active transaction, not cancelled, and only with
 * exactly the current total, a paid-on date not in the future and (for
 * uzqr_static / bank_transfer) a reference no other payment of the method
 * uses — all checked under the order lock.
 *
 * Writes a paid goods row in order_payments (who / when / channel /
 * reference), sets the order paid, logs it and alerts finance + sales
 * (reference masked, no buyer data). The status never changes here.
 */
class MarkOrderPaid
{
    use LocksOrder, RecordsManualPayment;

    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Order $order, ManualPaymentData $data, User $actor): OrderPayment
    {
        try {
            $payment = DB::transaction(function () use ($order, $data, $actor): OrderPayment {
                $order = $this->lockOrder($order);

                if ($order->payment_status !== PaymentStatus::Unpaid || $order->hasActiveTransaction()) {
                    throw ValidationException::withMessages(['payment' => __('orders.errors.not_unpaid')]);
                }

                if ($order->status === OrderStatus::Cancelled) {
                    throw ValidationException::withMessages(['payment' => __('orders.errors.cancelled')]);
                }

                if (! $order->payment_method->isManual() || $order->payment_method->value !== $data->method) {
                    throw ValidationException::withMessages(['method' => __('orders.errors.method_mismatch')]);
                }

                $checked = $this->checkManualPayment($data, $order->total);

                $payment = OrderPayment::query()->create([
                    'order_id' => $order->id,
                    'purpose' => OrderPaymentPurpose::Goods,
                    'method' => $checked['method'],
                    'receipt_channel' => $checked['receipt_channel'],
                    'status' => OrderPaymentStatus::Paid,
                    'amount' => $order->total,
                    'is_test' => $order->is_test,
                    'reference' => $checked['reference'],
                    'reference_key' => $checked['reference_key'],
                    'note' => $checked['note'],
                    'paid_on' => $checked['paid_on'],
                    'paid_at' => now(),
                    'recorded_by_id' => $actor->id,
                    'created_by_id' => $actor->id,
                ]);

                $order->forceFill(['payment_status' => PaymentStatus::Paid, 'paid_at' => now(), 'payment_pending_at' => null])->save();

                $this->log->handle($order, 'paid', $actor, [
                    'payment_id' => $payment->id,
                    'method' => $checked['method']->value,
                    'channel' => $checked['receipt_channel']?->value,
                    'amount' => $payment->amount,
                    'reference' => OrderPayment::maskReference($payment->reference),
                    'paid_on' => $checked['paid_on'],
                ], ActivityType::Payment);

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent payment of another order took the reference first.
            throw ValidationException::withMessages(['reference' => __('orders.errors.reference_used')]);
        }

        OrderPaid::dispatch($order->refresh(), $payment, $actor);

        return $payment;
    }
}
