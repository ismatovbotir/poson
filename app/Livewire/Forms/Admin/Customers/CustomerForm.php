<?php

namespace App\Livewire\Forms\Admin\Customers;

use App\Actions\Customers\SaveCustomer;
use App\Data\Customers\ContactData;
use App\Data\Customers\CustomerData;
use App\Enums\CustomerType;
use App\Enums\PriceLevel;
use App\Models\Customer;
use App\Rules\UzPhone;
use App\Support\Phone;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * State and validation of the customer form. Saved through SaveCustomer,
 * which re-checks INN uniqueness, the manager and the price level rule.
 */
class CustomerForm extends Form
{
    public string $type = 'company';

    public string $name = '';

    public string $inn = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $notes = '';

    /**
     * '' = unassigned, else a user id.
     */
    public string $manager_id = '';

    /**
     * Only filled for users who may change it (customers.price-level); '' otherwise.
     */
    public string $price_level = '';

    /**
     * Index of the primary contact in $contacts.
     */
    public int $primary = 0;

    /**
     * @var list<array{id: int|null, name: string, position: string, phone: string, email: string, telegram: string}>
     */
    public array $contacts = [];

    public function setCustomer(Customer $customer): void
    {
        $customer->loadMissing('contacts');

        $this->type = $customer->type->value;
        $this->name = $customer->name;
        $this->inn = (string) $customer->inn;
        $this->phone = $customer->phone ? Phone::display($customer->phone) : '';
        $this->email = (string) $customer->email;
        $this->address = (string) $customer->address;
        $this->notes = (string) $customer->notes;
        $this->manager_id = (string) $customer->manager_id;
        $this->price_level = $this->component->canChangePriceLevel() ? $customer->price_level->value : '';

        $contacts = $customer->contacts->sortBy('id')->values();
        $this->primary = max(0, (int) $contacts->search(fn ($contact) => $contact->is_primary));
        $this->contacts = $contacts->map(fn ($contact): array => [
            'id' => $contact->id,
            'name' => $contact->name,
            'position' => (string) $contact->position,
            'phone' => $contact->phone ? Phone::display($contact->phone) : '',
            'email' => (string) $contact->email,
            'telegram' => (string) $contact->telegram,
        ])->all();
    }

    public function addContact(): void
    {
        $this->contacts[] = ['id' => null, 'name' => '', 'position' => '', 'phone' => '', 'email' => '', 'telegram' => ''];
    }

    public function removeContact(int $index): void
    {
        unset($this->contacts[$index]);
        $this->contacts = array_values($this->contacts);

        if ($this->primary >= count($this->contacts) || $this->primary === $index) {
            $this->primary = 0;
        } elseif ($this->primary > $index) {
            $this->primary--;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['nullable', 'digits:9'],
            'phone' => ['nullable', 'string', 'max:32', new UzPhone],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'manager_id' => ['nullable', 'integer'],
            'price_level' => ['nullable', Rule::enum(PriceLevel::class)],
            'primary' => ['integer', 'min:0'],
            'contacts' => ['array', 'max:'.SaveCustomer::MAX_CONTACTS],
            'contacts.*' => ['array:id,name,position,phone,email,telegram'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.position' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:32', new UzPhone],
            'contacts.*.email' => ['nullable', 'string', 'email', 'max:255'],
            'contacts.*.telegram' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach (['type', 'name', 'inn', 'phone', 'email', 'address', 'notes', 'manager_id', 'price_level'] as $field) {
            $attributes[$field] = __('crm.customers.fields.'.$field);
        }

        if ($this->type === CustomerType::Individual->value) {
            $attributes['name'] = __('crm.customers.fields.full_name');
        }

        foreach (array_keys($this->contacts) as $index) {
            foreach (['name', 'position', 'phone', 'email', 'telegram'] as $field) {
                $attributes["contacts.{$index}.{$field}"] = __('crm.customers.contacts.'.$field);
            }
        }

        return $attributes;
    }

    /**
     * Input for SaveCustomer. The price level is sent only when the user may change it.
     */
    public function customerData(bool $withPriceLevel): CustomerData
    {
        return new CustomerData(
            type: CustomerType::from($this->type),
            name: $this->name,
            inn: $this->inn,
            phone: $this->phone,
            email: $this->email,
            address: $this->address,
            notes: $this->notes,
            managerId: filled($this->manager_id) ? (int) $this->manager_id : null,
            priceLevel: $withPriceLevel ? PriceLevel::tryFrom($this->price_level) : null,
            contacts: array_map(fn (array $contact, int $index): ContactData => new ContactData(
                name: (string) ($contact['name'] ?? ''),
                phone: $contact['phone'] ?? null,
                position: $contact['position'] ?? null,
                email: $contact['email'] ?? null,
                telegram: $contact['telegram'] ?? null,
                isPrimary: $index === $this->primary,
                id: isset($contact['id']) ? (int) $contact['id'] : null,
            ), array_values($this->contacts), array_keys(array_values($this->contacts))),
        );
    }
}
