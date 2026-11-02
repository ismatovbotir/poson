<?php

namespace App\Enums;

/**
 * State of copying a customer file to the team chat.
 */
enum TelegramForwardStatus: string
{
    case Pending = 'pending';
    case Forwarded = 'forwarded';
    case NotConfigured = 'not_configured';
    case Limited = 'limited';
    case Failed = 'failed';

    public function label(): string
    {
        return __("enums.telegram_forward_status.{$this->value}");
    }
}
