<?php

namespace App\Enums;

/**
 * Plan 46: a topic where the agent must call a human (escalations of an agent version).
 */
enum EscalationTrigger: string
{
    case Complaint = 'complaint';
    case LegalContract = 'legal_contract';
    case DiscountRequest = 'discount_request';
    case AngryTone = 'angry_tone';
    case PaymentDispute = 'payment_dispute';
    case CustomIntegration = 'custom_integration';

    public function label(): string
    {
        return __("enums.escalation_trigger.{$this->value}");
    }

    /**
     * The actions switched on by default (plan 46 §8, owner default 2).
     *
     * @return list<EscalationAction>
     */
    public function defaultActions(): array
    {
        return match ($this) {
            self::Complaint, self::AngryTone => [EscalationAction::Flag],
            self::LegalContract, self::DiscountRequest, self::PaymentDispute => [EscalationAction::Flag, EscalationAction::NoDraft],
            self::CustomIntegration => [EscalationAction::RaiseUrgency],
        };
    }

    /**
     * Whether the actions are fixed in code and cannot be changed or switched off (ED46-1: never negotiate a discount).
     */
    public function isLocked(): bool
    {
        return $this === self::DiscountRequest;
    }
}
