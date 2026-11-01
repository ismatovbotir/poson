<?php

namespace App\Actions\Payments\Uzqr\Concerns;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\UzqrTransaction;

/**
 * Lock order first, then the UzQR row (security condition 37), inside the
 * caller's transaction; and give a held order back to "unpaid" once
 * nothing holds it any more (only after a bank-confirmed final status).
 */
trait LocksUzqrTransaction
{
    /**
     * @return array{0: Order, 1: UzqrTransaction}
     */
    protected function lockPair(UzqrTransaction $transaction): array
    {
        $order = Order::query()->whereKey($transaction->order_id)->lockForUpdate()->firstOrFail();
        $locked = UzqrTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

        return [$order, $locked];
    }

    /**
     * pending → unpaid when no online transaction holds the order any more.
     */
    protected function releaseOrder(Order $order): void
    {
        if ($order->payment_status === PaymentStatus::Pending && ! $order->hasActiveTransaction()) {
            $order->forceFill(['payment_status' => PaymentStatus::Unpaid, 'payment_pending_at' => null])->save();
        }
    }
}
