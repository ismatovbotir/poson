<?php

namespace App\Support\Orders;

use App\Enums\DeliveryPricing;
use App\Enums\DeliveryType;
use App\Enums\Region;

/**
 * The delivery price of an order (plan 11 v2 §1.2): pickup and courier in
 * Kokand are fixed 0; a region courier is the region's fee from Settings →
 * Checkout (fixed, in the total) or, without a fee, "separate" (0 in the
 * total; the manager confirms it and bills a delivery charge).
 */
final readonly class DeliveryQuote
{
    public function __construct(
        public DeliveryPricing $pricing,
        public int $amount,
    ) {}

    public static function for(DeliveryType $type, ?Region $region, CheckoutSettings $settings): self
    {
        if ($type !== DeliveryType::CourierRegion) {
            return new self(DeliveryPricing::Fixed, 0);
        }

        $fee = $region !== null ? $settings->regionFee($region) : null;

        return $fee !== null ? new self(DeliveryPricing::Fixed, $fee) : new self(DeliveryPricing::Separate, 0);
    }

    public function isSeparate(): bool
    {
        return $this->pricing === DeliveryPricing::Separate;
    }
}
