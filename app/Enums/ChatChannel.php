<?php

namespace App\Enums;

/**
 * Where a chat happens (plan 28): the website widget or the Telegram bot.
 * Never add admin or AI usage channels here (plan 32 §14 plan change 3:
 * AiUsageChannel); loops use bots(), cases() is banned outside this enum
 * (arch test).
 */
enum ChatChannel: string
{
    case Web = 'web';
    case Telegram = 'telegram';

    public function label(): string
    {
        return __("enums.chat_channel.{$this->value}");
    }

    /**
     * Every bot channel (the loop source for screens, settings and switches).
     *
     * @return list<self>
     */
    public static function bots(): array
    {
        return self::cases();
    }
}
