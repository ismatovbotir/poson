<?php

namespace App\Enums;

/**
 * How the order reaches the customer (plan 11 v2 §1.2): pickup (0),
 * courier in Kokand (0 soʻm, the address is verified on the confirmation
 * call), courier to a region (the region's fee from Settings → Checkout, or
 * billed separately when the region has none).
 */
enum DeliveryType: string
{
    case Pickup = 'pickup';
    case CourierKokand = 'courier_kokand';
    case CourierRegion = 'courier_region';

    public function label(): string
    {
        return __("enums.delivery_type.{$this->value}");
    }

    public function needsAddress(): bool
    {
        return $this !== self::Pickup;
    }
}
