<?php

namespace App\Enums;

/**
 * Product kind (autoid-domain): hardware, consumables, software and services
 * share the products table.
 */
enum ProductType: string
{
    case Hardware = 'hardware';
    case Consumable = 'consumable';
    case Software = 'software';
    case Service = 'service';

    public function label(): string
    {
        return __("enums.product_type.{$this->value}");
    }

    /**
     * Types a service engineer may manage (products.manage-services).
     */
    public function isServiceManaged(): bool
    {
        return in_array($this, [self::Software, self::Service], true);
    }
}
