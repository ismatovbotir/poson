<?php

namespace App\Enums;

/**
 * Plan 46: what staff did with an AI draft.
 */
enum DraftOutcome: string
{
    case Used = 'used';
    case Edited = 'edited';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __("enums.draft_outcome.{$this->value}");
    }
}
