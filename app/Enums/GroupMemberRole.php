<?php

namespace App\Enums;

/**
 * The role of a group member, set by staff (plan 44 §2). Bots are always
 * "bot" and the AI never sets a role.
 */
enum GroupMemberRole: string
{
    case Employee = 'employee';
    case Client = 'client';
    case Bot = 'bot';
    case Unknown = 'unknown';

    public function label(): string
    {
        return __("enums.group_member_role.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Employee => 'sky',
            self::Client => 'brand',
            self::Bot => 'muted',
            self::Unknown => 'amber',
        };
    }
}
