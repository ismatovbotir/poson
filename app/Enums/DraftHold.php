<?php

namespace App\Enums;

/**
 * Plan 46: why a draft was held back or not stored (analysis and thread draft_hold, varchar 16).
 */
enum DraftHold: string
{
    case Escalation = 'escalation';
    case LowConfidence = 'low_confidence';
    case Language = 'language';
    case Sla = 'sla';
    case Forbidden = 'forbidden';

    public function label(): string
    {
        return __("enums.draft_hold.{$this->value}");
    }
}
