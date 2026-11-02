<?php

namespace App\Enums;

/**
 * Delivery state of a bot message.
 */
enum TelegramMessageStatus: string
{
    case Received = 'received';
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return __("enums.telegram_message_status.{$this->value}");
    }
}
