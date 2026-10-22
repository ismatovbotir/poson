<?php

namespace App\Data\Customers;

/**
 * One contact person in SaveCustomer. id: an existing contact of the same
 * customer (updated), null for a new one. The phone may be in any format.
 */
final readonly class ContactData
{
    public function __construct(
        public string $name,
        public ?string $phone = null,
        public ?string $position = null,
        public ?string $email = null,
        public ?string $telegram = null,
        public bool $isPrimary = false,
        public ?int $id = null,
    ) {}
}
