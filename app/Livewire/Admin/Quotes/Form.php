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
use App\Models\RollType;
use App\Models\User;
use App\Support\Catalog\RollPricing;
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
 *
 * Label products (plan 08) become roll lines: a roll type picker (new lines;
 * the first active roll type is preselected), rolls as qty, an editable
 * per-label price and the derived per-roll price; fewer rolls than the
 * product's minimum only shows a warning.
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
            $products = Product::query()->whereIn('id', $lead->items->pluck('product_id')->filter()->unique()->values())->with('rollTypes')->get()->keyBy('id');

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
            ->get(['id', 'sku', 'name', 'unit', 'pricing_unit', 'price', 'price_dealer', 'price_wholesale', 'is_active']);
    }

    /**
     * Active roll types of the label products on new lines (product id => roll types), for the pickers.
     *
     * @return array<int, list<array{id: int, label: string}>>
     */
    #[Computed]
    public function rollTypeOptions(): array
    {
        $productIds = collect($this->form->items)
            ->filter(fn (array $item): bool => ($item['id'] ?? null) === null && QuoteForm::isRollRow($item) && ($item['product_id'] ?? null) !== null)
            ->pluck('product_id')
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        return Product::query()
            ->whereKey($productIds)
            ->with('activeRollTypes')
            ->get(['id'])
            ->mapWithKeys(fn (Product $product): array => [$product->id => $product->activeRollTypes->map(fn (RollType $rollType): array => ['id' => $rollType->id, 'label' => $rollType->label()])->values()->all()])
            ->all();
    }

    /**
     * A new label line's roll type changed: refresh its roll snapshot shown in the form.
     */
    public function updated(string $name, mixed $value): void
    {
        if (! preg_match('/^form\.items\.(\d+)\.roll_type_id$/', $name, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $item = $this->form->items[$index] ?? null;

        if ($item === null || ($item['id'] ?? null) !== null || ! QuoteForm::isRollRow($item)) {
            return;
        }

        $product = Product::query()->with('activeRollTypes')->find($item['product_id']);
        $rollType = $product?->activeRollTypes->firstWhere('id', (int) $value);

        if ($rollType instanceof RollType) {
            $this->form->items[$index]['labels_per_roll'] = $rollType->labels_per_roll;
            $this->form->items[$index]['core_diameter_mm'] = $rollType->core_diameter_mm;
        }
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
                $levelPrice = Money::tiyinToSums($products->get($item['product_id'])?->priceFor($customer->price_level) ?? 0);
                $this->form->items[$index][QuoteForm::isRollRow($item) ? 'price_per_label' : 'price'] = $levelPrice;
            }
        }
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->with('rollTypes')->find($productId);

        if (! $product instanceof Product) {
            return;
        }

        $this->form->items[] = $this->productRow($product, max(1, $product->min_qty));

        $this->productSearch = '';
        unset($this->productResults, $this->rollTypeOptions);
    }

    public function addLine(): void
    {
        $this->form->items[] = ['id' => null, 'product_id' => null, 'name' => '', 'sku' => null, 'unit' => null, 'qty' => 1, 'price' => '', ...self::NO_ROLL];
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
     * Roll fields of a line that is not a label line.
     */
    private const NO_ROLL = ['roll_type_id' => null, 'labels_per_roll' => null, 'core_diameter_mm' => null, 'label_size' => null, 'price_per_label' => '', 'min_qty' => null];

    /**
     * A new product row at the customer's level price; a label product (plan 08)
     * becomes a roll line in the wanted roll type when offered, else the first
     * active one. Needs "rollTypes" loaded.
     *
     * @return array<string, mixed>
     */
    private function productRow(Product $product, int $qty, ?int $wantedRollType = null): array
    {
        $levelPrice = Money::tiyinToSums($product->priceFor($this->priceLevel) ?? 0);
        $row = [
            'id' => null,
            'product_id' => $product->id,
            'name' => $product->getTranslation('name', $this->form->locale),
            'sku' => $product->sku,
            'unit' => $product->unit->value,
            'qty' => $qty,
            'price' => $levelPrice,
            ...self::NO_ROLL,
        ];

        if (! $product->isPricedPerLabel()) {
            return $row;
        }

        $rollType = $product->rollTypes->firstWhere('id', CreateQuote::rollTypeFor($product, $wantedRollType));

        return [
            ...$row,
            'unit' => 'roll',
            'price' => '',
            'roll_type_id' => $rollType?->id,
            // Without an offered roll type the line still shows as a roll line; saving asks for one.
            'labels_per_roll' => $rollType?->labels_per_roll ?? 0,
            'core_diameter_mm' => $rollType?->core_diameter_mm,
            'label_size' => RollPricing::labelSize($product),
            'price_per_label' => $levelPrice,
            'min_qty' => $product->min_qty,
        ];
    }

    /**
     * A form row for a lead item at the customer's level price (the lead's roll for label products).
     *
     * @return array<string, mixed>
     */
    private function rowFromLeadItem(LeadItem $item, ?Product $product): array
    {
        if ($product !== null) {
            return $this->productRow($product, $item->qty, $item->roll_type_id);
        }

        return ['id' => null, 'product_id' => null, 'name' => $item->product_name, 'sku' => $item->sku, 'unit' => null, 'qty' => $item->qty, 'price' => Money::tiyinToSums(0), ...self::NO_ROLL];
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
