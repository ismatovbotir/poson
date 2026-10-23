<?php

namespace App\Livewire\Forms\Admin\Quotes;

use App\Actions\Quotes\SaveQuoteItems;
use App\Data\Quotes\QuoteData;
use App\Data\Quotes\QuoteItemData;
use App\Models\Quote;
use App\Support\Money;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * State and validation of the quote form (plan 06). Prices are typed in
 * whole soʻm (VAT included) and converted to tiyin here. Lines: existing
 * ones keep their snapshots (id), new product lines carry product_id, free
 * lines a name.
 */
class QuoteForm extends Form
{
    public string $customer_id = '';

    public string $lead_id = '';

    public string $valid_until = '';

    public string $notes = '';

    public string $locale = 'uz';

    /**
     * @var list<array{id: int|null, product_id: int|null, name: string, sku: string|null, unit: string|null, qty: int|string, price: string}>
     */
    public array $items = [];

    public function setQuote(Quote $quote): void
    {
        $quote->loadMissing('items');

        $this->customer_id = (string) $quote->customer_id;
        $this->lead_id = (string) $quote->lead_id;
        $this->valid_until = $quote->valid_until->toDateString();
        $this->notes = (string) $quote->notes;
        $this->locale = $quote->locale;
        $this->items = $quote->items->map(fn ($item): array => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->name,
            'sku' => $item->sku,
            'unit' => $item->unit?->value,
            'qty' => $item->qty,
            'price' => Money::tiyinToSums($item->price),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $price = function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && Money::sumsToTiyin((string) $value) === null) {
                $fail(__('crm.quotes.errors.price_invalid'));
            }
        };

        return [
            'customer_id' => ['required', 'integer'],
            'lead_id' => ['nullable', 'integer'],
            'valid_until' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'locale' => ['required', Rule::in(config('app.supported_locales'))],
            'items' => ['array', 'min:1', 'max:'.SaveQuoteItems::MAX_ITEMS],
            'items.*' => ['array:id,product_id,name,sku,unit,qty,price'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['nullable', 'string', 'max:255', 'required_without_all:items.*.id,items.*.product_id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:'.SaveQuoteItems::MAX_QTY],
            'items.*.price' => ['nullable', 'string', 'max:24', $price],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'customer_id' => __('crm.quotes.fields.customer'),
            'valid_until' => __('crm.quotes.fields.valid_until'),
            'notes' => __('crm.quotes.fields.notes'),
            'locale' => __('crm.quotes.fields.locale'),
            'items' => __('crm.quotes.items.title'),
        ];

        foreach (array_keys($this->items) as $index) {
            $attributes["items.{$index}.name"] = __('crm.quotes.items.name');
            $attributes["items.{$index}.qty"] = __('crm.quotes.items.qty');
            $attributes["items.{$index}.price"] = __('crm.quotes.items.price');
        }

        return $attributes;
    }

    /**
     * Input for CreateQuote / UpdateQuote.
     */
    public function quoteData(): QuoteData
    {
        return new QuoteData(
            customerId: (int) $this->customer_id,
            leadId: filled($this->lead_id) ? (int) $this->lead_id : null,
            items: array_map(fn (array $item): QuoteItemData => new QuoteItemData(
                productId: isset($item['product_id']) ? (int) $item['product_id'] : null,
                name: $item['name'] ?? null,
                qty: (int) ($item['qty'] ?? 1),
                // Blank: the level price for a product line, the current price for a saved line, 0 for free text.
                price: filled($item['price'] ?? null) ? Money::sumsToTiyin((string) $item['price']) : null,
                itemId: isset($item['id']) ? (int) $item['id'] : null,
            ), array_values($this->items)),
            validUntil: $this->valid_until,
            notes: $this->notes,
            locale: $this->locale,
        );
    }

    /**
     * Live total in tiyin (from the typed prices).
     */
    public function total(): int
    {
        $sum = 0;

        foreach ($this->items as $item) {
            $price = filled($item['price'] ?? null) ? (Money::sumsToTiyin((string) $item['price']) ?? 0) : 0;
            $sum += $price * max(0, (int) ($item['qty'] ?? 0));
        }

        return $sum;
    }
}
