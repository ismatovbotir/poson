<?php

namespace App\Enums;

/**
 * Product card badge. Brand rule: only "hit" and "new", never a sale badge.
 */
enum ProductBadge: string
{
    case Hit = 'hit';
    case New = 'new';

    public function label(): string
    {
        return __("enums.product_badge.{$this->value}");
    }
}
