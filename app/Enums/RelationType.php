<?php

namespace App\Enums;

/**
 * How two products are related (a printer and its labels, a scanner and its stand, ...).
 */
enum RelationType: string
{
    case Accessory = 'accessory';
    case Consumable = 'consumable';
    case Alternative = 'alternative';
    case Software = 'software';

    public function label(): string
    {
        return __("enums.relation_type.{$this->value}");
    }
}
