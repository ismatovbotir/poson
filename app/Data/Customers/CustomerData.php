<?php

namespace App\Data\Customers;

use App\Enums\CustomerType;
use App\Enums\PriceLevel;

/**
 * Input of SaveCustomer (admin form, lead conversion). Phones may be in any
 * format; SaveCustomer normalizes and validates everything.
 *
 * priceLevel: null keeps the current level (Retail for a new customer);
 * only users with customers.price-level may change it.
 */
final readonly class CustomerData
{
    /**
     * @param  list<ContactData>  $contacts
     */
    public function __construct(
        public CustomerType $type,
        public string $name,
        public ?string $inn = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $notes = null,
        public ?int $managerId = null,
        public ?PriceLevel $priceLevel = null,
        public array $contacts = [],
        public ?string $locale = null,
    ) {}
}
