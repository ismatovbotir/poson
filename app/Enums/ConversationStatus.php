<?php

namespace App\Enums;

/**
 * Inbox state of a bot conversation.
 */
enum ConversationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return __("enums.conversation_status.{$this->value}");
    }
}
