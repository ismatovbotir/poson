<?php

namespace App\Enums;

enum StockStatus: string
{
    case InStock = 'in_stock';
    case OnOrder = 'on_order';
    case OutOfStock = 'out_of_stock';

    public function label(): string
    {
        return __("enums.stock_status.{$this->value}");
    }

    /**
     * Badge color for <x-admin.badge>.
     */
    public function color(): string
    {
        return match ($this) {
            self::InStock => 'brand',
            self::OnOrder => 'sky',
            self::OutOfStock => 'red',
        };
    }
}
