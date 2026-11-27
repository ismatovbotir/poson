<?php

namespace App\Enums;

/**
 * Plan 46: what an enabled escalation trigger does.
 */
enum EscalationAction: string
{
    case Flag = 'flag';
    case NoDraft = 'no_draft';
    case RaiseUrgency = 'raise_urgency';

    public function label(): string
    {
        return __("enums.escalation_action.{$this->value}");
    }
}
