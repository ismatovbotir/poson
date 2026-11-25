<?php

namespace App\Enums;

/**
 * Who wrote a stored Telegram Business message (plan 41 §2). "bot" is for
 * phase 2 (messages the bot sends on the owner's behalf); phase 1 never
 * stores it.
 */
enum BusinessAuthor: string
{
    case Client = 'client';
    case Owner = 'owner';
    case Bot = 'bot';

    public function label(): string
    {
        return __("enums.business_author.{$this->value}");
    }
}
