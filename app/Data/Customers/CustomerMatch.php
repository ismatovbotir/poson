<?php

namespace App\Data\Customers;

use App\Models\Customer;

/**
 * A customer found by FindCustomerMatches and why: "inn", "phone" (the
 * customer's own) or "contact_phone".
 */
final readonly class CustomerMatch
{
    public const INN = 'inn';

    public const PHONE = 'phone';

    public const CONTACT_PHONE = 'contact_phone';

    public function __construct(
        public Customer $customer,
        public string $reason,
    ) {}
}
