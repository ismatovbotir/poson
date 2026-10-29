<?php

namespace App\Actions\Orders\Concerns;

use App\Models\Order;

/**
 * Re-read an order under a row lock (inside the caller's transaction), so
 * every check runs on the current state (security condition 3: lock the
 * order first, then any transaction / payment row).
 */
trait LocksOrder
{
    protected function lockOrder(Order $order): Order
    {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        $order->setRawAttributes($locked->getAttributes(), true);

        return $locked;
    }
}
