<?php

namespace App\Livewire\Forms\Admin\Leads;

use App\Actions\Leads\SyncLeadItems;
use App\Actions\Leads\UpdateLead;
use App\Data\Leads\LeadData;
use App\Data\Leads\LeadItemData;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Models\Lead;
use App\Models\User;
use App\Rules\UzPhone;
use App\Support\Money;
use App\Support\Phone;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * State and validation of the admin lead form (create / edit). Saved
 * through CreateLead / UpdateLead. The estimate is typed in whole soʻm and
 * converted to tiyin here. Items: existing rows keep their snapshots (id),
 * new rows carry a product picked in the search.
 */
class LeadForm extends Form
{
    public string $source = 'phone';

    public string $type = 'callback';

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $company = '';

    public string $inn = '';

    public string $message = '';

    /**
     * Whole soʻm ('' = no estimate).
     */
    public string $estimated_amount = '';

    /**
     * Manager on create: '' = unassigned, else a user id.
     */
    public string $manager_id = '';

    /**
     * @var list<array{id: int|null, product_id: int|null, sku: string|null, name: string, qty: int|string, note: string}>
     */
    public array $items = [];

    public function setLead(Lead $lead): void
    {
        $lead->loadMissing('items');

        $this->source = $lead->source->value;
        $this->type = $lead->type->value;
        $this->name = $lead->name;
        $this->phone = Phone::display($lead->phone);
        $this->email = (string) $lead->email;
        $this->company = (string) $lead->company;
        $this->inn = (string) $lead->inn;
        $this->message = (string) $lead->message;
        $this->estimated_amount = Money::tiyinToSums($lead->estimated_amount);
        $this->manager_id = (string) $lead->manager_id;
        $this->items = $lead->items->map(fn ($item): array => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'sku' => $item->sku,
            'name' => $item->product_name,
            'qty' => $item->qty,
            'note' => (string) $item->note,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $lead = $this->component->lead ?? null;
        $sources = array_map(fn (LeadSource $source): string => $source->value, UpdateLead::MANUAL_SOURCES);

        // A site (or other non-manual) lead keeps its source.
        if ($lead instanceof Lead && ! in_array($lead->source, UpdateLead::MANUAL_SOURCES, true)) {
            $sources = [$lead->source->value];
        }

        return [
            'source' => ['required', Rule::in($sources)],
            // Only the types the form offers (the user's types; never checkout by hand).
            'type' => ['required', Rule::in(array_map(fn (LeadType $type): string => $type->value, $this->component->typeOptions))],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new UzPhone],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:160'],
            'inn' => ['nullable', 'digits:9'],
            'message' => ['nullable', 'string', 'max:5000'],
            'estimated_amount' => ['nullable', 'string', 'max:24', function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && Money::sumsToTiyin((string) $value) === null) {
                    $fail(__('crm.leads.errors.amount_invalid'));
                }
            }],
            'manager_id' => ['nullable', 'integer'],
            'items' => ['array', 'max:'.SyncLeadItems::MAX_ITEMS],
            'items.*' => ['array:id,product_id,sku,name,qty,note'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer', 'required_without:items.*.id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:'.SyncLeadItems::MAX_QTY],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach (['source', 'type', 'name', 'phone', 'email', 'company', 'inn', 'message', 'estimated_amount', 'manager_id'] as $field) {
            $attributes[$field] = __('crm.leads.fields.'.$field);
        }

        foreach (array_keys($this->items) as $index) {
            $attributes["items.{$index}.qty"] = __('crm.leads.items.qty');
            $attributes["items.{$index}.note"] = __('crm.leads.items.note');
        }

        return $attributes;
    }

    /**
     * Input for CreateLead / UpdateLead.
     */
    public function leadData(User $actor): LeadData
    {
        return new LeadData(
            source: LeadSource::from($this->source),
            type: LeadType::from($this->type),
            name: trim($this->name),
            phone: $this->phone,
            locale: $this->component->lead?->locale ?? config('app.locale'),
            email: $this->email,
            company: $this->company,
            inn: $this->inn,
            message: $this->message,
            items: array_map(fn (array $item): LeadItemData => new LeadItemData(
                productId: $item['product_id'] !== null ? (int) $item['product_id'] : null,
                qty: (int) $item['qty'],
                note: $item['note'],
                itemId: $item['id'] !== null ? (int) $item['id'] : null,
            ), array_values($this->items)),
            managerId: filled($this->manager_id) ? (int) $this->manager_id : null,
            createdById: $actor->id,
            estimatedAmount: filled($this->estimated_amount) ? Money::sumsToTiyin($this->estimated_amount) : null,
        );
    }
}
