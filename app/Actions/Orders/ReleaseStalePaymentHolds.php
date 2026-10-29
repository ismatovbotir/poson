<?php

namespace App\Actions\Orders;

use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Release orders stuck in "pending" (plan 11 v2 §1.6): an order whose
 * online hold ended (no active Payme state-1 transaction younger than
 * 12 h, no Click prepare younger than 60 min; Order::hasActiveTransaction())
 * goes back to unpaid, so it can be paid again or its method changed.
 * Scheduled every 10 minutes. Each order is re-checked under its lock.
 */
class ReleaseStalePaymentHolds
{
    /**
     * @return int how many orders were released
     */
    public function handle(): int
    {
        $released = 0;

        Order::query()
            ->where('payment_status', PaymentStatus::Pending->value)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$released): void {
                DB::transaction(function () use ($id, &$released): void {
                    $order = Order::query()->whereKey($id)->lockForUpdate()->first();

                    if ($order instanceof Order && $order->payment_status === PaymentStatus::Pending && ! $order->hasActiveTransaction()) {
                        $order->forceFill(['payment_status' => PaymentStatus::Unpaid, 'payment_pending_at' => null])->save();
                        $released++;
                    }
                });
            });

        return $released;
    }
}
