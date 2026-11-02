<?php

namespace App\Enums;

/**
 * Registration step of a bot user (plan 14).
 */
enum TelegramUserState: string
{
    case Language = 'language';
    case Contact = 'contact';
    case Name = 'name';
    case Company = 'company';
    case Inn = 'inn';
    case Registered = 'registered';

    public function label(): string
    {
        return __("enums.telegram_user_state.{$this->value}");
    }
}
