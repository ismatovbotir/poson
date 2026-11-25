<?php

namespace App\Events\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order's status changed (plan 20 M5 §6.4, MA-14): dispatched by every
 * status writer (ChangeOrderStatus, and RecordOrderRefund when a refund
 * cancels the order), only after the transaction commits (a rolled-back
 * change never announces anything). Carries no reason text (MA-26).
 * NotifyTelegramCustomer turns it into a customer message for Mini App
 * orders.
 */
class OrderStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public OrderStatus $from,
        public OrderStatus $to,
    ) {}
}
