<?php

namespace App\Enums;

/**
 * Who buys (plan 11 v2 §1.4): a person, a company (STIR ^[23]\d{8}$) or a
 * sole proprietor / YaTT (STIR ^[4-6]\d{8}$ or PINFL ^[3-6]\d{13}$).
 * Bank transfer is for companies and sole proprietors only.
 */
enum BuyerType: string
{
    case Individual = 'individual';
    case Company = 'company';
    case SoleProprietor = 'sole_proprietor';

    public function label(): string
    {
        return __("enums.buyer_type.{$this->value}");
    }

    /**
     * Needs a company name and tax id; may pay by bank transfer.
     */
    public function isBusiness(): bool
    {
        return $this !== self::Individual;
    }
}
