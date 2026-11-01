<?php

namespace App\Enums;

/**
 * Outcome of a paid UzQR invoice (plan 11e §3): settled (the order is paid)
 * or unmatched (money received but the order could not take it; an Admin
 * resolves it, condition 50).
 */
enum UzqrSettlement: string
{
    case Settled = 'settled';
    case Unmatched = 'unmatched';

    public function label(): string
    {
        return __("enums.uzqr_settlement.{$this->value}");
    }
}
