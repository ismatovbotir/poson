<?php

namespace App\Data\Orders;

/**
 * Delivery fields of an order as staff type them (raw strings).
 * deliveryFee: a fixed courier fee in tiyin typed by staff for a region
 * order (null = the region's fee from Settings → Checkout, or billed
 * separately when it has none); applied only while the lines may change.
 */
final readonly class OrderDeliveryData
{
    public function __construct(
        public string $deliveryType,
        public string $region = '',
        public string $address = '',
        public bool $kokandConfirmed = false,
        public ?int $deliveryFee = null,
    ) {}
}
