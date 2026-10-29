<?php

namespace App\Data\Orders;

/**
 * A manual (or TEST) order created by staff in admin: buyer details,
 * delivery, payment method (raw string), lines, an optional CRM customer
 * (its price level prices the new lines), the order language and whether
 * it is a TEST order (Admin, payments.test).
 */
final readonly class ManualOrderData
{
    /**
     * @param  list<OrderLineData>  $lines
     */
    public function __construct(
        public OrderDetailsData $details,
        public OrderDeliveryData $delivery,
        public string $paymentMethod,
        public array $lines,
        public ?int $customerId = null,
        public string $locale = 'uz',
        public bool $isTest = false,
    ) {}
}
