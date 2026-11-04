<?php

namespace App\Enums;

/**
 * State of copying a customer file to the team chat. "sending" is the
 * claim taken by ForwardTelegramMedia before it calls Telegram (TG-25).
 */
enum TelegramForwardStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Forwarded = 'forwarded';
    case NotConfigured = 'not_configured';
    case Limited = 'limited';
    case Failed = 'failed';

    /**
     * States a forward job may claim (pending → sending, or a staff retry
     * of an unsent file). Forwarded and sending are never claimed again.
     *
     * @return list<self>
     */
    public static function claimable(): array
    {
        return [self::Pending, self::Failed, self::NotConfigured, self::Limited];
    }

    public function label(): string
    {
        return __("enums.telegram_forward_status.{$this->value}");
    }
}
