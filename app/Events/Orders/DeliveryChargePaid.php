<?php

namespace App\Events\Orders;

/**
 * A separately billed delivery charge was paid → finance chat.
 */
class DeliveryChargePaid extends OrderPaymentEvent
{
    public function kind(): string
    {
        return 'delivery_paid';
    }

    public function telegramChats(): array
    {
        return ['finance'];
    }
}
