<?php

namespace App\Enums;

/**
 * Channel a lead came in through.
 */
enum LeadSource: string
{
    case Site = 'site';
    case Telegram = 'telegram';
    case Phone = 'phone';
    case Manual = 'manual';
    // Plan 41: a lead from an analysed Telegram Business chat (Virtual office).
    case TelegramBusiness = 'tg_business';

    public function label(): string
    {
        return __("enums.lead_source.{$this->value}");
    }
}
