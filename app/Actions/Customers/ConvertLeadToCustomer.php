<?php

namespace App\Actions\Customers;

use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\CreateLead;
use App\Data\Customers\ContactData;
use App\Data\Customers\CustomerData;
use App\Enums\ActivityType;
use App\Enums\CustomerType;
use App\Enums\LeadStatus;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Convert an open lead into a customer (plan 05b, C6). The only way a lead
 * becomes "converted" (ChangeLeadStatus::markConverted()).
 *
 *  - With a customer (picked from FindCustomerMatches or anywhere): the lead
 *    is linked; a customer without a manager takes the lead's manager
 *    (logged); the lead's person is added as a contact when their phone is
 *    new to the customer (logged in the conversion entry).
 *  - Without: a new customer is created through SaveCustomer (all its
 *    rules: INN, phones, manager), prefilled from the lead unless data is
 *    given, with the lead's person as the primary contact.
 *  - The new customer's manager is the lead's manager when that user works
 *    with sales leads (a service engineer is not made customer manager); an
 *    unassigned lead converted by a sales user makes that user the manager.
 *  - "conversion" activities on the lead and on the customer.
 *
 * Components authorize first (LeadPolicy::convert, and CustomerPolicy::create
 * for a new customer).
 */
class ConvertLeadToCustomer
{
    public function __construct(
        protected SaveCustomer $saveCustomer,
        protected ChangeLeadStatus $changeStatus,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, ?Customer $customer, User $actor, ?CustomerData $newCustomer = null): Customer
    {
        return DB::transaction(function () use ($lead, $customer, $actor, $newCustomer): Customer {
            // Lock the lead and re-check: two people converting at once can't both win.
            $status = Lead::query()->whereKey($lead->id)->lockForUpdate()->value('status');
            $status = $status instanceof LeadStatus ? $status : LeadStatus::tryFrom((string) $status);

            if ($status === null || ! $status->isOpen()) {
                throw ValidationException::withMessages(['status' => __('crm.customers.errors.lead_closed')]);
            }

            $leadManager = $this->salesManager($lead);
            $created = $customer === null;
            $contact = null;

            if ($customer === null) {
                // An unassigned lead converted by a sales user: the new customer is theirs.
                $manager = $leadManager ?? ($lead->manager_id === null && $actor->can('leads.sales') ? $actor : null);
                $customer = $this->saveCustomer->handle(null, $this->withLeadContact($lead, $newCustomer ?? static::dataFromLead($lead), $manager), $actor);
            } else {
                if ($customer->manager_id === null && $leadManager !== null) {
                    $customer->update(['manager_id' => $leadManager->id]);
                    SaveCustomer::logManagerChange($customer, $actor, null, $leadManager->id, 'auto');
                }

                $contact = $this->addLeadContact($customer, $lead);
            }

            $lead->customer_id = $customer->id;
            $lead->save();

            $this->changeStatus->markConverted($lead, $actor);

            $lead->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::Conversion,
                'meta' => ['customer_id' => $customer->id, 'customer_name' => $customer->name, 'created' => $created],
                'created_at' => now(),
            ]);

            $customer->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::Conversion,
                'meta' => array_filter([
                    'lead_id' => $lead->id,
                    'created' => $created,
                    'contact_id' => $contact?->id,
                    'contact_name' => $contact?->name,
                ], fn (mixed $value): bool => $value !== null),
                'created_at' => now(),
            ]);

            return $customer;
        });
    }

    /**
     * The lead's person as a new contact of an existing customer when their
     * phone is neither the customer's nor a contact's (decision 2026-10-22);
     * primary only when the customer has no contacts. Done for whoever
     * converts, also without the right to edit the customer.
     */
    private function addLeadContact(Customer $customer, Lead $lead): ?Contact
    {
        $phone = Phone::normalize($lead->phone);

        if ($phone === null || $customer->phone === $phone || Contact::query()->where('customer_id', $customer->id)->where('phone', $phone)->exists()) {
            return null;
        }

        return Contact::query()->create([
            'customer_id' => $customer->id,
            'name' => mb_substr(trim($lead->name), 0, 255),
            'phone' => $phone,
            'email' => CreateLead::clean($lead->email, 255, lower: true),
            'is_primary' => ! Contact::query()->where('customer_id', $customer->id)->exists(),
        ]);
    }

    /**
     * A new customer prefilled from the lead: a company when the lead names one.
     */
    public static function dataFromLead(Lead $lead): CustomerData
    {
        $company = filled($lead->company) ? trim((string) $lead->company) : null;

        return new CustomerData(
            type: $company !== null ? CustomerType::Company : CustomerType::Individual,
            name: $company ?? $lead->name,
            inn: $lead->inn,
            phone: $lead->phone,
            email: $lead->email,
            locale: $lead->locale,
        );
    }

    /**
     * The lead's manager when they work with sales leads, else null.
     */
    private function salesManager(Lead $lead): ?User
    {
        if ($lead->manager_id === null) {
            return null;
        }

        $manager = User::query()->whereKey($lead->manager_id)->where('is_active', true)->first();

        return $manager?->can('leads.sales') ? $manager : null;
    }

    /**
     * The data with the lead's manager and the lead's person as the primary contact.
     */
    private function withLeadContact(Lead $lead, CustomerData $data, ?User $manager): CustomerData
    {
        $contacts = $data->contacts !== [] ? $data->contacts : [
            new ContactData(name: $lead->name, phone: $lead->phone, email: $lead->email, isPrimary: true),
        ];

        return new CustomerData(
            type: $data->type,
            name: $data->name,
            inn: $data->inn,
            phone: $data->phone,
            email: $data->email,
            address: $data->address,
            notes: $data->notes,
            managerId: $data->managerId ?? $manager?->id,
            priceLevel: $data->priceLevel,
            contacts: $contacts,
            locale: $data->locale ?? $lead->locale,
        );
    }
}
