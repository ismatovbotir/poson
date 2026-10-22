<?php

namespace App\Actions\Customers;

use App\Data\Customers\CustomerMatch;
use App\Models\Contact;
use App\Models\Customer;
use App\Support\Phone;

/**
 * Customers a lead may belong to (plan 05 C6): the INN match first, then
 * customers with the phone, then customers whose contact has the phone.
 * Each customer once, in that order; the manager picks when there are
 * several.
 */
class FindCustomerMatches
{
    public const LIMIT = 10;

    /**
     * @return list<CustomerMatch>
     */
    public function handle(?string $phone, ?string $inn): array
    {
        $matches = [];
        $inn = trim((string) $inn);
        $phone = Phone::normalize($phone);

        if (preg_match('/^\d{9}$/', $inn)) {
            foreach (Customer::query()->with('manager:id,name')->where('inn', $inn)->get() as $customer) {
                $matches[$customer->id] = new CustomerMatch($customer, CustomerMatch::INN);
            }
        }

        if ($phone !== null) {
            foreach (Customer::query()->with('manager:id,name')->where('phone', $phone)->orderBy('id')->limit(self::LIMIT)->get() as $customer) {
                $matches[$customer->id] ??= new CustomerMatch($customer, CustomerMatch::PHONE);
            }

            $contacts = Contact::query()->with('customer.manager:id,name')->where('phone', $phone)->orderBy('id')->limit(self::LIMIT)->get();

            foreach ($contacts as $contact) {
                $matches[$contact->customer_id] ??= new CustomerMatch($contact->customer, CustomerMatch::CONTACT_PHONE);
            }
        }

        return array_slice(array_values($matches), 0, self::LIMIT);
    }
}
