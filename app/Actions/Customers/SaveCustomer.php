<?php

namespace App\Actions\Customers;

use App\Actions\Leads\CreateLead;
use App\Data\Customers\ContactData;
use App\Data\Customers\CustomerData;
use App\Enums\ActivityType;
use App\Enums\PriceLevel;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a customer with its contacts (plan 05b). Components
 * authorize first (CustomerPolicy); this action keeps the data rules:
 *
 *  - name required; INN optional, exactly 9 digits and unique;
 *  - phones (customer and contacts) normalized to +998XXXXXXXXX;
 *  - contacts: existing ones by id (of this customer only), new ones
 *    created, the rest deleted; exactly one primary when there are any
 *    (the first flagged one, else the first);
 *  - price level: changes need customers.price-level (Admin); otherwise the
 *    current level is kept (Retail for a new customer);
 *  - manager: unchanged, unassigned, or one of managerCandidates();
 *  - history: "created" for a new customer; on an update, manager changes
 *    ("assignment", from / to) and price level changes (from / to);
 *  - a duplicate INN that slips past the check (two saves at once) still
 *    comes back as the inn_taken validation error.
 */
class SaveCustomer
{
    public const MAX_CONTACTS = 20;

    /**
     * @throws ValidationException
     */
    public function handle(?Customer $customer, CustomerData $data, User $actor): Customer
    {
        $customer ??= new Customer;
        $errors = [];

        $name = trim($data->name);
        $inn = CreateLead::clean($data->inn, 32);
        $phone = CreateLead::clean($data->phone, 32);
        $normalizedPhone = $phone !== null ? Phone::normalize($phone) : null;

        if ($name === '') {
            $errors['name'] = __('validation.required', ['attribute' => __('crm.customers.fields.name')]);
        }

        if ($inn !== null && ! preg_match('/^\d{9}$/', $inn)) {
            $errors['inn'] = __('crm.customers.errors.inn_format');
        } elseif ($inn !== null && Customer::query()->where('inn', $inn)->when($customer->exists, fn ($query) => $query->whereKeyNot($customer->id))->exists()) {
            $errors['inn'] = __('crm.customers.errors.inn_taken');
        }

        if ($phone !== null && $normalizedPhone === null) {
            $errors['phone'] = __('validation.uz_phone', ['attribute' => __('crm.customers.fields.phone')]);
        }

        $priceLevel = $customer->exists ? $customer->price_level : PriceLevel::Retail;

        if ($data->priceLevel !== null && $data->priceLevel !== $priceLevel) {
            if (! $actor->can('customers.price-level')) {
                $errors['price_level'] = __('crm.customers.errors.price_level_forbidden');
            } else {
                $priceLevel = $data->priceLevel;
            }
        }

        if ($data->managerId !== null && $data->managerId !== $customer->manager_id && ! $this->managerCandidates($actor)->contains('id', $data->managerId)) {
            $errors['manager_id'] = __('crm.customers.errors.manager_invalid');
        }

        $contacts = $this->contacts($customer, $data->contacts, $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            return $this->persist($customer, $data, $actor, $name, $inn, $normalizedPhone, $priceLevel, $contacts);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['inn' => __('crm.customers.errors.inn_taken')]);
        }
    }

    /**
     * Record a manager change in the customer's history.
     *
     * @param  string  $action  "assign" (form) or "auto" (conversion)
     */
    public static function logManagerChange(Customer $customer, User $actor, ?int $fromId, ?int $toId, string $action = 'assign'): void
    {
        $names = User::query()->whereKey(array_filter([$fromId, $toId]))->pluck('name', 'id');

        $customer->activities()->create([
            'user_id' => $actor->id,
            'type' => ActivityType::Assignment,
            'meta' => [
                'action' => $action,
                'from_id' => $fromId,
                'from_name' => $fromId !== null ? $names->get($fromId) : null,
                'to_id' => $toId,
                'to_name' => $toId !== null ? $names->get($toId) : null,
            ],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<array{id: int|null, attributes: array<string, mixed>}>  $contacts
     */
    private function persist(Customer $customer, CustomerData $data, User $actor, string $name, ?string $inn, ?string $normalizedPhone, PriceLevel $priceLevel, array $contacts): Customer
    {
        return DB::transaction(function () use ($customer, $data, $actor, $name, $inn, $normalizedPhone, $priceLevel, $contacts): Customer {
            $creating = ! $customer->exists;
            $previousManagerId = $customer->manager_id;
            $previousLevel = $customer->price_level;

            $customer->fill([
                'type' => $data->type,
                'name' => mb_substr($name, 0, 255),
                'inn' => $inn,
                'phone' => $normalizedPhone,
                'email' => CreateLead::clean($data->email, 255, lower: true),
                'address' => CreateLead::clean($data->address, 500),
                'notes' => CreateLead::clean($data->notes, 5000),
                'price_level' => $priceLevel,
                'manager_id' => $data->managerId,
                'locale' => $creating ? ($data->locale ?? config('app.locale')) : $customer->locale,
            ])->save();

            $kept = [];
            $existing = Contact::query()
                ->where('customer_id', $customer->id)
                ->whereIn('id', array_filter(array_column($contacts, 'id')))
                ->get()
                ->keyBy('id');

            foreach ($contacts as $contact) {
                $row = $contact['id'] !== null ? $existing->get($contact['id']) : new Contact;

                if (! $row instanceof Contact) {
                    throw ValidationException::withMessages(['contacts' => __('crm.customers.errors.contact_missing')]);
                }

                $row->fill(array_merge($contact['attributes'], ['customer_id' => $customer->id]))->save();
                $kept[] = $row->id;
            }

            Contact::query()->where('customer_id', $customer->id)->whereNotIn('id', $kept)->delete();

            if ($creating) {
                $customer->activities()->create([
                    'user_id' => $actor->id,
                    'type' => ActivityType::Created,
                    'created_at' => now(),
                ]);

                return $customer;
            }

            if ($previousManagerId !== $customer->manager_id) {
                static::logManagerChange($customer, $actor, $previousManagerId, $customer->manager_id);
            }

            if ($previousLevel !== $customer->price_level) {
                $customer->activities()->create([
                    'user_id' => $actor->id,
                    'type' => ActivityType::PriceLevelChange,
                    'meta' => ['from' => $previousLevel?->value, 'to' => $customer->price_level->value],
                    'created_at' => now(),
                ]);
            }

            return $customer;
        });
    }

    /**
     * People the actor may make a customer's manager: with
     * customers.update-any, or customers.update + customers.reassign (sales
     * managers handing a customer over, decision 2026-10-22), any active
     * user working with sales leads; with customers.update alone only
     * themselves.
     *
     * @return Collection<int, User>
     */
    public function managerCandidates(User $actor): Collection
    {
        if ($actor->can('customers.update-any') || ($actor->can('customers.update') && $actor->can('customers.reassign'))) {
            return User::query()->permission('leads.sales')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        }

        return $actor->can('customers.update') ? new Collection([$actor]) : new Collection;
    }

    /**
     * Validated contact rows ready to save.
     *
     * @param  list<ContactData>  $contacts
     * @param  array<string, string>  $errors
     * @return list<array{id: int|null, attributes: array<string, mixed>}>
     */
    private function contacts(Customer $customer, array $contacts, array &$errors): array
    {
        if (count($contacts) > self::MAX_CONTACTS) {
            $errors['contacts'] = __('crm.customers.errors.too_many_contacts', ['max' => self::MAX_CONTACTS]);

            return [];
        }

        $ownIds = $customer->exists ? Contact::query()->where('customer_id', $customer->id)->pluck('id')->all() : [];
        $primaryIndex = null;

        foreach (array_values($contacts) as $index => $contact) {
            if ($contact->isPrimary) {
                $primaryIndex = $index;

                break;
            }
        }

        $rows = [];

        foreach (array_values($contacts) as $index => $contact) {
            if ($contact->id !== null && ! in_array($contact->id, $ownIds, true)) {
                $errors['contacts'] = __('crm.customers.errors.contact_missing');

                continue;
            }

            $name = trim($contact->name);
            $phone = CreateLead::clean($contact->phone, 32);
            $normalized = $phone !== null ? Phone::normalize($phone) : null;

            if ($name === '') {
                $errors["contacts.{$index}.name"] = __('validation.required', ['attribute' => __('crm.customers.contacts.name')]);
            }

            if ($phone !== null && $normalized === null) {
                $errors["contacts.{$index}.phone"] = __('validation.uz_phone', ['attribute' => __('crm.customers.contacts.phone')]);
            }

            $rows[] = [
                'id' => $contact->id,
                'attributes' => [
                    'name' => mb_substr($name, 0, 255),
                    'position' => CreateLead::clean($contact->position, 255),
                    'phone' => $normalized,
                    'email' => CreateLead::clean($contact->email, 255, lower: true),
                    'telegram' => CreateLead::clean($contact->telegram, 64),
                    'is_primary' => $index === ($primaryIndex ?? 0),
                ],
            ];
        }

        return $rows;
    }
}
