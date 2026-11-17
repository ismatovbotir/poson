<?php

namespace App\Enums;

/**
 * Who wrote a website chat message (plan 28 C5, web_chat_messages.author):
 * the visitor, the rule bot, a staff member, or a system line for staff
 * (hand-off, ban), which the visitor never sees.
 */
enum ChatAuthor: string
{
    case Visitor = 'visitor';
    case Bot = 'bot';
    case Staff = 'staff';
    case System = 'system';

    public function label(): string
    {
        return __("enums.chat_author.{$this->value}");
    }
}
