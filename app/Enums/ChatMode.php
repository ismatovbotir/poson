<?php

namespace App\Enums;

/**
 * Who answers a chat: the rule bot or a staff member (telegram_users.chat_mode).
 */
enum ChatMode: string
{
    case Bot = 'bot';
    case Manager = 'manager';

    public function label(): string
    {
        return __("enums.chat_mode.{$this->value}");
    }
}
