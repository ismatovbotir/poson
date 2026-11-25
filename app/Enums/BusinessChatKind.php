<?php

namespace App\Enums;

/**
 * What an Admin marked a Telegram Business chat as (plan 41 §4). Only client
 * chats store messages and get analysed; unmarked ones are attention stubs
 * without content (VO-4), employee and ignored ones store nothing.
 */
enum BusinessChatKind: string
{
    case Unmarked = 'unmarked';
    case Client = 'client';
    case Employee = 'employee';
    case Ignored = 'ignored';

    public function label(): string
    {
        return __("enums.business_chat_kind.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Unmarked => 'amber',
            self::Client => 'brand',
            self::Employee => 'sky',
            self::Ignored => 'muted',
        };
    }

    /**
     * The kinds an Admin can mark a chat as.
     *
     * @return list<self>
     */
    public static function markable(): array
    {
        return [self::Client, self::Employee, self::Ignored];
    }
}
