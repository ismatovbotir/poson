<?php

namespace App\Enums;

/**
 * Delivery price of an order: fixed (in the order total, possibly 0) or
 * separate (confirmed by the manager and billed as its own delivery charge;
 * the order total never changes).
 */
enum DeliveryPricing: string
{
    case Fixed = 'fixed';
    case Separate = 'separate';

    public function label(): string
    {
        return __("enums.delivery_pricing.{$this->value}");
    }
}
