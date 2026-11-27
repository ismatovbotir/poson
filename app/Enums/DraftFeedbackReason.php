<?php

namespace App\Enums;

/**
 * Plan 46: why staff edited or rejected an AI draft.
 */
enum DraftFeedbackReason: string
{
    case WrongRole = 'wrong_role';
    case WrongFacts = 'wrong_facts';
    case Tone = 'tone';
    case TooLong = 'too_long';
    case MissingInfo = 'missing_info';
    case Brand = 'brand';
    case Other = 'other';

    public function label(): string
    {
        return __("enums.draft_feedback_reason.{$this->value}");
    }
}
