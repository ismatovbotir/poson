<?php

namespace App\Enums;

/**
 * Who wrote a stored AI conversation turn (plan 30): the customer (redacted
 * text) or the AI assistant (filtered answer).
 */
enum ChatAiRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    public function label(): string
    {
        return __("enums.chat_ai_role.{$this->value}");
    }
}
