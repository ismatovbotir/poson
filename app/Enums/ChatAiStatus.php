<?php

namespace App\Enums;

/**
 * How one AI answer ended (plan 30, assistant rows of chat_ai_turns):
 *  - answered: sent to the customer;
 *  - truncated: cut at max_tokens, sent up to the last full sentence;
 *  - failed: timeout, API or empty answer; the rule reply was sent instead;
 *  - refused: the model refused; the rule reply was sent instead;
 *  - blocked: the output filter stopped it (brand, money, links, contacts); the rule reply was sent;
 *  - capped: a limit or daily cap stopped the call; the rule reply was sent;
 *  - superseded: a newer message, a hand-off, a dialog or a ban made it moot (nothing was sent),
 *    or a rule now answers it directly (stop_reason "direct": the rule reply was sent, no call);
 *  - disabled: AI was switched off or lost its key while the turn waited; the rule reply was sent.
 */
enum ChatAiStatus: string
{
    case Answered = 'answered';
    case Truncated = 'truncated';
    case Failed = 'failed';
    case Refused = 'refused';
    case Blocked = 'blocked';
    case Capped = 'capped';
    case Superseded = 'superseded';
    case Disabled = 'disabled';

    public function label(): string
    {
        return __("enums.chat_ai_status.{$this->value}");
    }

    /**
     * The customer got the AI text.
     */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Answered, self::Truncated], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Answered => 'brand',
            self::Truncated => 'sky',
            self::Failed, self::Blocked => 'red',
            self::Refused, self::Capped => 'amber',
            self::Superseded, self::Disabled => 'muted',
        };
    }
}
