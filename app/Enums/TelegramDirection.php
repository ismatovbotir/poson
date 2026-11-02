<?php

namespace App\Enums;

/**
 * Direction of a bot message: from the customer (in) or from staff / the bot (out).
 */
enum TelegramDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return __("enums.telegram_direction.{$this->value}");
    }
}
