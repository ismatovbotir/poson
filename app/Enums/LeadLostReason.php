<?php

namespace App\Enums;

/**
 * Why a lead was lost. "other" needs a note (ChangeLeadStatus).
 */
enum LeadLostReason: string
{
    case NoResponse = 'no_response';
    case NotRelevant = 'not_relevant';
    case BoughtElsewhere = 'bought_elsewhere';
    case OutOfStock = 'out_of_stock';
    case Budget = 'budget';
    case Duplicate = 'duplicate';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return __("enums.lead_lost_reason.{$this->value}");
    }

    public function requiresNote(): bool
    {
        return $this === self::Other;
    }
}
