<?php

namespace App\Actions\Orders;

use App\Actions\Orders\Concerns\LocksOrder;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a TEST order with its lines, payment rows and history (plan 11 v2
 * §1.7, security condition 29), in one transaction. Real orders are never
 * deleted, not even by an Admin; any non-test payment row (or, from 11c /
 * 11d, a live-mode provider transaction) on the order is a hard failure.
 */
class DeleteTestOrder
{
    use LocksOrder;

    /**
     * @throws ValidationException
     */
    public function handle(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = $this->lockOrder($order);

            if (! $order->is_test) {
                throw ValidationException::withMessages(['order' => __('orders.errors.delete_real')]);
            }

            if (OrderPayment::query()->where('order_id', $order->id)->where('is_test', false)->exists()) {
                throw ValidationException::withMessages(['order' => __('orders.errors.delete_real_payment')]);
            }

            OrderPayment::query()->where('order_id', $order->id)->where('is_test', true)->delete();
            $order->activities()->delete();
            $order->items()->delete();
            $order->delete();
        });
    }
}
