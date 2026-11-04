<?php

namespace App\Actions\Telegram;

use RuntimeException;

/**
 * A staff inbox action refused for a business reason (plan 21 stream A): a
 * state a policy cannot express because Admins pass it through Gate::before
 * (T21-25), or a lost race. The reason is a fixed code, translated for the
 * toast; it never carries message text or a Telegram id. Policy refusals stay
 * AuthorizationException (403).
 */
class InboxRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Telegram inbox action refused: {$reason}");
    }

    public function translated(): string
    {
        return __('telegram_inbox.refused.'.$this->reason);
    }
}
