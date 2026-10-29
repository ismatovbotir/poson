<?php

namespace App\Events\Orders;

/**
 * A manual payment was voided by an Admin (condition 22) → finance chat.
 */
class ManualPaymentVoided extends OrderPaymentEvent
{
    public function kind(): string
    {
        return 'voided';
    }

    public function telegramChats(): array
    {
        return ['finance'];
    }
}
