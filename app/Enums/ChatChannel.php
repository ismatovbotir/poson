<?php

namespace App\Enums;

/**
 * Where a chat happens (plan 28): the website widget or the Telegram bot.
 */
enum ChatChannel: string
{
    case Web = 'web';
    case Telegram = 'telegram';

    public function label(): string
    {
        return __("enums.chat_channel.{$this->value}");
    }
}
