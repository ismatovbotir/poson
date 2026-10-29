<?php

namespace App\Actions\Orders;

use App\Enums\ActivityType;
use App\Enums\OrderPaymentStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tick a manually confirmed payment off against the bank statement, or
 * untick it (security condition 21): payments.reconcile (Admin, authorized
 * by the caller), paid manual payments only; who / when stored on the row
 * and logged in the order history.
 */
class SetPaymentReconciled
{
    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(OrderPayment $payment, bool $reconciled, User $actor): void
    {
        DB::transaction(function () use ($payment, $reconciled, $actor): void {
            $payment = OrderPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== OrderPaymentStatus::Paid || ! $payment->isManual()) {
                throw ValidationException::withMessages(['payment' => __('orders.errors.reconcile_manual_only')]);
            }

            if (($payment->reconciled_at !== null) === $reconciled) {
                return;
            }

            $payment->forceFill([
                'reconciled_at' => $reconciled ? now() : null,
                'reconciled_by_id' => $reconciled ? $actor->id : null,
            ])->save();

            $order = Order::query()->findOrFail($payment->order_id);
            $this->log->handle($order, $reconciled ? 'reconciled' : 'unreconciled', $actor, ['payment_id' => $payment->id, 'amount' => $payment->amount], ActivityType::Payment);
        });
    }
}
