<?php

namespace App\Enums;

/**
 * Unit a product is sold in.
 */
enum ProductUnit: string
{
    case Piece = 'piece';
    case Roll = 'roll';
    case Box = 'box';
    case Pack = 'pack';
    case License = 'license';
    case Hour = 'hour';

    public function label(): string
    {
        return __("enums.product_unit.{$this->value}");
    }
}
