<?php

namespace App\Enums;

/**
 * What a product's prices are per (plan 08).
 *
 * - Unit: the prices are per sales unit (piece, roll, box, ...), as usual.
 * - Label: consumable labels sold in rolls. price / price_dealer /
 *   price_wholesale are per ONE label in whole soʻm (tiyin divisible by 100);
 *   the product's unit is a roll and min_qty counts rolls. The roll types
 *   attached to the product (labels per roll + core) turn the per-label price
 *   into a per-roll price (App\Support\Catalog\RollPricing).
 */
enum ProductPricingUnit: string
{
    case Unit = 'unit';
    case Label = 'label';

    public function label(): string
    {
        return __("enums.product_pricing_unit.{$this->value}");
    }
}
