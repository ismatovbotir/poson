<?php

namespace App\Events\Orders;

/**
 * A refund was recorded (goods or a delivery charge) → finance + sales chats.
 */
class OrderRefunded extends OrderPaymentEvent
{
    public function kind(): string
    {
        return 'refunded';
    }

    public function telegramChats(): array
    {
        return ['finance', 'sales'];
    }
}
