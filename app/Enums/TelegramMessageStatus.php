<?php

namespace App\Enums;

/**
 * Delivery state of a bot message.
 */
enum TelegramMessageStatus: string
{
    case Received = 'received';
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';

    /**
     * Not sent on purpose: an order status message whose order or customer
     * was no longer eligible when the job ran (plan 20 M5, MA-33).
     */
    case Skipped = 'skipped';

    public function label(): string
    {
        return __("enums.telegram_message_status.{$this->value}");
    }
}
