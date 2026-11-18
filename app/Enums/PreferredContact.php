<?php

namespace App\Enums;

/**
 * How a service-request client wants to be reached (plan 36).
 */
enum PreferredContact: string
{
    case Call = 'call';
    case Telegram = 'telegram';

    public function label(): string
    {
        return __("enums.preferred_contact.{$this->value}");
    }
}
