<?php

namespace App\Enums;

/**
 * Who a Telegram person is to the company (plan 41 §4), set by an Admin when
 * a Telegram Business chat or a bot conversation is marked. Never used for
 * AI caps (VO-11: employees are counted like everyone else).
 */
enum TelegramParty: string
{
    case Client = 'client';
    case Employee = 'employee';

    public function label(): string
    {
        return __("enums.telegram_party.{$this->value}");
    }
}
