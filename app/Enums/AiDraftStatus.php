<?php

namespace App\Enums;

/**
 * State of an AI product wizard draft (plan 32 §2.2, PW-8). Every stage
 * start and finish is one conditional UPDATE from an allowed set of states.
 *
 * open → seeing → facts_ready → writing → ready | truncated → applying → applied;
 * a stage can also end failed, refused or capped (retry of that stage only);
 * cancel or the TTL makes it expired.
 */
enum AiDraftStatus: string
{
    case Open = 'open';
    case Seeing = 'seeing';
    case FactsReady = 'facts_ready';
    case Writing = 'writing';
    case Ready = 'ready';
    case Truncated = 'truncated';
    case Failed = 'failed';
    case Refused = 'refused';
    case Capped = 'capped';
    case Applying = 'applying';
    case Applied = 'applied';
    case Expired = 'expired';

    public function label(): string
    {
        return __("enums.ai_draft_status.{$this->value}");
    }

    /**
     * A stage job is queued or running.
     */
    public function isPending(): bool
    {
        return in_array($this, self::pending(), true);
    }

    /**
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::Seeing, self::Writing];
    }

    /**
     * No further stage or apply is possible.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Applied, self::Expired], true);
    }

    /**
     * States a vision stage may start from.
     *
     * @return list<self>
     */
    public static function visionFrom(): array
    {
        return [self::Open, self::FactsReady, self::Failed, self::Refused, self::Capped];
    }

    /**
     * States a write stage may start from (never "open": the facts come from
     * stage A or, improve, from prefill() → facts_ready; L4).
     *
     * @return list<self>
     */
    public static function writeFrom(): array
    {
        return [self::FactsReady, self::Ready, self::Truncated, self::Failed, self::Refused, self::Capped];
    }

    /**
     * @param  list<self>  $statuses
     * @return list<string>
     */
    public static function values(array $statuses): array
    {
        return array_map(fn (self $status): string => $status->value, $statuses);
    }
}
