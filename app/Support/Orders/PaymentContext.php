<?php

namespace App\Support\Orders;

use App\Enums\BuyerType;
use App\Enums\DeliveryType;

/**
 * What PaymentOptions needs to know about an order (being placed or
 * already stored): delivery type, buyer type, the goods total payable now
 * (tiyin; delivery included only when it is fixed), whether every line
 * (and a fixed paid delivery) has an IKPU, and whether it is a TEST order.
 */
final readonly class PaymentContext
{
    public function __construct(
        public DeliveryType $deliveryType,
        public BuyerType $buyerType,
        public int $total,
        public bool $ikpuComplete,
        public bool $isTest = false,
    ) {}
}
