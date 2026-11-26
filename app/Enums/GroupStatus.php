<?php

namespace App\Enums;

/**
 * What an Admin decided for a Telegram group the bot is in (plan 44 §2).
 * Only tracked groups store members and messages; untracked ones are stubs
 * (OG-2), ignored ones store nothing.
 */
enum GroupStatus: string
{
    case Untracked = 'untracked';
    case Tracked = 'tracked';
    case Ignored = 'ignored';

    public function label(): string
    {
        return __("enums.group_status.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Untracked => 'amber',
            self::Tracked => 'brand',
            self::Ignored => 'muted',
        };
    }
}
