<?php

namespace App\Livewire\Admin\Quotes;

use App\Actions\Quotes\CreateQuote;
use App\Actions\Quotes\UpdateQuote;
use App\Enums\PriceLevel;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Quotes\QuoteForm;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Support\Money;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a quote (plan 06): customer picker (create only), lines
 * with a product picker showing the price for the customer's level, qty,
 * editable price (whole soʻm, VAT included), free-text lines, a live total
 * with the VAT line, validity and notes. From a lead (?lead=ID) the
 * customer and the lead's items are prefilled; ?customer=ID preselects.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?Quote $quote = null;

    public QuoteForm $form;

    public string $customerSearch = '';

    public string $productSearch = '';

    public function mount(?Quote $quote = null): void
    {
        if ($quote?->exists) {
            $this->authorize('update', $quote);
            $this->quote = $quote;
            $this->form->setQuote($quote);

            return;
        }

        $this->authorize('create', Quote::class);

        $leadId = request()->integer('lead');
        $customerId = request()->integer('customer');

        if ($leadId > 0) {
            $lead = Lead::query()->with('items')->find($leadId);

            if ($lead instanceof Lead && static::canQuoteFromLead($this->user(), $lead)) {
                $this->form->lead_id = (string) $lead->id;
                $customerId = $lead->customer_id;
            }
        }

        if ($customerId > 0) {
            $this->selectCustomer($customerId);
        }

        if (isset($lead) && $this->form->lead_id !== '') {
            $products = Product::query()->whereIn('id', $lead->items->pluck('product_id')->filter()->unique()->values())->get()->keyBy('id');

            foreach ($lead->items as $item) {
                $this->form->items[] = $this->rowFromLeadItem($item, $item->product_id !== null ? $products->get($item->product_id) : null);
            }
        }

        $this->form->valid_until = CreateQuote::validUntil(null)->toDateString();
    }

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerResults(): Collection
    {
        if ($this->quote !== null || mb_strlen(trim($this->customerSearch)) < 2) {
            return new Collection;
        }

        return Customer::query()->visibleTo($this->user())->search($this->customerSearch)->orderBy('name')->limit(8)->get(['id', 'name', 'inn', 'price_level']);
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return ctype_digit($this->form->customer_id) ? Customer::query()->find((int) $this->form->customer_id, ['id', 'name', 'inn', 'price_level', 'locale']) : null;
    }

    /**
     * The customer's price level (the quote's snapshot when editing).
     */
    #[Computed]
    public function priceLevel(): PriceLevel
    {
        return $this->quote?->price_level ?? $this->customer?->price_level ?? PriceLevel::Retail;
    }

    /**
     * Picker results with the price for the customer's level.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function productResults(): Collection
    {
        if (mb_strlen(trim($this->productSearch)) < 2) {
            return new Collection;
        }

        return Product::query()
            ->whereRaw("search_text like ? escape '!'", [Search::normalizedPattern($this->productSearch)])
            ->orderByDesc('is_active')
            ->orderBy('sku')
            ->limit(10)
            ->get(['id', 'sku', 'name', 'unit', 'price', 'price_dealer', 'price_wholesale', 'is_active']);
    }

    public function selectCustomer(int $customerId): void
    {
        if ($this->quote !== null) {
            return;
        }

        $customer = Customer::query()->visibleTo($this->user())->find($customerId);

        if (! $customer instanceof Customer) {
            return;
        }

        $this->authorize('create', [Quote::class, $customer]);

        $this->form->customer_id = (string) $customer->id;
        $this->form->locale = $customer->locale;
        $this->customerSearch = '';
        unset($this->customer, $this->priceLevel, $this->customerResults);

        // New product lines follow the new customer's level (one query for all of them).
        $products = Product::query()
            ->whereIn('id', collect($this->form->items)->filter(fn (array $item): bool => ($item['id'] ?? null) === null)->pluck('product_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        foreach ($this->form->items as $index => $item) {
            if (($item['id'] ?? null) === null && ($item['product_id'] ?? null) !== null) {
                $this->form->items[$index]['price'] = Money::tiyinToSums($products->get($item['product_id'])?->priceFor($customer->price_level) ?? 0);
            }
        }
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->find($productId);

        if (! $product instanceof Product) {
            return;
        }

        $this->form->items[] = [
            'id' => null,
            'product_id' => $product->id,
            'name' => $product->getTranslation('name', $this->form->locale),
            'sku' => $product->sku,
            'unit' => $product->unit->value,
            'qty' => max(1, $product->min_qty),
            'price' => Money::tiyinToSums($product->priceFor($this->priceLevel) ?? 0),
        ];

        $this->productSearch = '';
        unset($this->productResults);
    }

    public function addLine(): void
    {
        $this->form->items[] = ['id' => null, 'product_id' => null, 'name' => '', 'sku' => null, 'unit' => null, 'qty' => 1, 'price' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->form->items[$index]);
        $this->form->items = array_values($this->form->items);
    }

    public function save(CreateQuote $createQuote, UpdateQuote $updateQuote): void
    {
        if ($this->quote !== null) {
            $this->authorize('update', $this->quote);
        } else {
            $this->authorize('create', [Quote::class, $this->customer]);
        }

        $this->form->validate();

        $data = $this->form->quoteData();

        if ($this->quote === null) {
            if ($data->leadId !== null) {
                $lead = Lead::query()->find($data->leadId);
                abort_unless($lead instanceof Lead && static::canQuoteFromLead($this->user(), $lead), 403);
            }

            $quote = $this->runFormAction(fn (): Quote => $createQuote->handle($data, $this->user()));
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('crm.quotes.toast.created', ['number' => $quote->number]), 'type' => 'success']);
        } else {
            $quote = $this->runFormAction(fn (): Quote => $updateQuote->handle($this->quote, $data, $this->user()));
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('crm.quotes.toast.saved', ['number' => $quote->number]), 'type' => 'success']);
        }

        $this->redirectRoute('admin.quotes.show', $quote, navigate: true);
    }

    public function render(): View
    {
        $title = $this->quote ? __('crm.quotes.edit_title', ['number' => $this->quote->number]) : __('crm.quotes.new');
        $total = $this->form->total();

        return view('livewire.admin.quotes.form', [
            'title' => $title,
            'total' => $total,
            'vat' => Quote::vatOf($total),
        ])->title($title);
    }

    /**
     * A form row for a lead item at the customer's level price.
     *
     * @return array{id: null, product_id: int|null, name: string, sku: string|null, unit: string|null, qty: int, price: string}
     */
    private function rowFromLeadItem(LeadItem $item, ?Product $product): array
    {
        return [
            'id' => null,
            'product_id' => $product?->id,
            'name' => $product?->getTranslation('name', $this->form->locale) ?? $item->product_name,
            'sku' => $product?->sku ?? $item->sku,
            'unit' => $product?->unit->value,
            'qty' => $item->qty,
            'price' => Money::tiyinToSums($product?->priceFor($this->priceLevel) ?? 0),
        ];
    }

    /**
     * The one rule for "create a quote from this lead" (form and lead page):
     * the user can see the lead, it has a customer, and the user may create quotes.
     */
    public static function canQuoteFromLead(User $user, Lead $lead): bool
    {
        return $lead->customer_id !== null && $user->can('view', $lead) && $user->can('create', Quote::class);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
