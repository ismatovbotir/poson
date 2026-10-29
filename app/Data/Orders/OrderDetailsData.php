<?php

namespace App\Data\Orders;

/**
 * Buyer and note fields of an order as staff type them (raw strings; the
 * actions parse the enums and validate).
 */
final readonly class OrderDetailsData
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $buyerType,
        public string $companyName = '',
        public string $tin = '',
        public string $comment = '',
        public string $staffNote = '',
        public ?int $managerId = null,
    ) {}
}
