<?php

namespace App\Enums;

/**
 * Customer price level. The site and the public cart only ever use Retail;
 * Dealer and Wholesale apply to admin quotes and orders for the customer.
 */
enum PriceLevel: string
{
    case Retail = 'retail';
    case Dealer = 'dealer';
    case Wholesale = 'wholesale';

    public function label(): string
    {
        return __("enums.price_level.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Retail => 'slate',
            self::Dealer => 'sky',
            self::Wholesale => 'violet',
        };
    }

    /**
     * products column that holds this level's price (tiyin).
     */
    public function column(): string
    {
        return match ($this) {
            self::Retail => 'price',
            self::Dealer => 'price_dealer',
            self::Wholesale => 'price_wholesale',
        };
    }
}
