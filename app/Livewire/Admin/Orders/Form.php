<?php

namespace App\Livewire\Admin\Orders;

use App\Actions\Orders\CreateManualOrder;
use App\Actions\Orders\UpdateOrder;
use App\Enums\BuyerType;
use App\Enums\DeliveryType;
use App\Enums\OrderSource;
use App\Enums\PriceLevel;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Orders\OrderForm;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\RollType;
use App\Models\User;
use App\Support\Catalog\RollPricing;
use App\Support\Money;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\DeliveryQuote;
use App\Support\Orders\PaymentContext;
use App\Support\Orders\PaymentOptions;
use App\Support\Search;
use App\Support\Vat;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create a manual (or, for Admins, TEST) order, or edit an order (plan
 * 11b). New lines take the customer's price level (fallback Retail) on
 * manual orders and Retail from the catalog on site orders; staff prices
 * stay within the floor unless the user has orders.price-override. On edit
 * each part follows the editing rules (§1.7): details until completed,
 * delivery until shipped, lines and payment method while unpaid and new /
 * confirmed — the actions re-check them under the order lock, all in one
 * transaction. Payment methods offered come from PaymentOptions.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?Order $order = null;

    public OrderForm $form;

    public string $customerSearch = '';

    public string $productSearch = '';

    public function mount(?Order $order = null): void
    {
        if ($order?->exists) {
            $this->authorize('update', $order);
            abort_if($order->status->isClosed(), 403);
            $this->order = $order;
            $this->form->setOrder($order);

            return;
        }

        $this->authorize('create', Order::class);
        $this->form->manager_id = (string) $this->user()->id;

        $customerId = request()->integer('customer');

        if ($customerId > 0) {
            $this->selectCustomer($customerId);
        }
    }

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerResults(): Collection
    {
        if (mb_strlen(trim($this->customerSearch)) < 2) {
            return new Collection;
        }

        return Customer::query()->visibleTo($this->user())->search($this->customerSearch)->orderBy('name')->limit(8)->get(['id', 'name', 'inn', 'price_level']);
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return ctype_digit($this->form->customer_id) ? Customer::query()->find((int) $this->form->customer_id, ['id', 'name', 'inn', 'phone', 'price_level', 'locale', 'type']) : null;
    }

    /**
     * Prices of new lines: the order's snapshot (edit), Retail for site orders, else the customer's level.
     */
    #[Computed]
    public function priceLevel(): PriceLevel
    {
        if ($this->order !== null) {
            return $this->order->source === OrderSource::Site ? PriceLevel::Retail : $this->order->price_level;
        }

        return $this->customer?->price_level ?? PriceLevel::Retail;
    }

    /**
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
            ->when($this->isSiteOrder(), fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_active')
            ->orderBy('sku')
            ->limit(10)
            ->get(['id', 'sku', 'name', 'unit', 'pricing_unit', 'price', 'price_dealer', 'price_wholesale', 'is_active']);
    }

    /**
     * @return array<int, list<array{id: int, label: string}>>
     */
    #[Computed]
    public function rollTypeOptions(): array
    {
        $productIds = collect($this->form->items)
            ->filter(fn (array $item): bool => ($item['id'] ?? null) === null && OrderForm::isRollRow($item) && ($item['product_id'] ?? null) !== null)
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
     * Active Admins and sales managers.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function managerOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->role([Role::Admin->value, Role::SalesManager->value])
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function updated(string $name, mixed $value): void
    {
        if (! preg_match('/^form\.items\.(\d+)\.roll_type_id$/', $name, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $item = $this->form->items[$index] ?? null;

        if ($item === null || ($item['id'] ?? null) !== null || ! OrderForm::isRollRow($item)) {
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
        $customer = Customer::query()->visibleTo($this->user())->find($customerId);

        if (! $customer instanceof Customer) {
            return;
        }

        $this->form->customer_id = (string) $customer->id;
        $this->customerSearch = '';

        if ($this->order === null) {
            $this->form->locale = $customer->locale;
            $this->form->name = $this->form->name !== '' ? $this->form->name : $customer->name;
            $this->form->phone = $this->form->phone !== '' ? $this->form->phone : (string) $customer->phone;

            if ($customer->inn !== null && $this->form->tin === '') {
                $this->form->tin = $customer->inn;
                $this->form->company_name = $customer->name;
                $this->form->buyer_type = str_starts_with($customer->inn, '2') || str_starts_with($customer->inn, '3') ? BuyerType::Company->value : BuyerType::SoleProprietor->value;
            }
        }

        unset($this->customer, $this->priceLevel, $this->customerResults);
        $this->repriceNewLines();
    }

    public function clearCustomer(): void
    {
        $this->form->customer_id = '';
        unset($this->customer, $this->priceLevel);
        $this->repriceNewLines();
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->with('rollTypes')->find($productId);

        if (! $product instanceof Product || count($this->form->items) >= Order::MAX_LINES) {
            return;
        }

        $this->form->items[] = $this->productRow($product, max(1, $product->min_qty));
        $this->productSearch = '';
        unset($this->productResults, $this->rollTypeOptions);
    }

    /**
     * A free-text line (manual orders, orders.price-override).
     */
    public function addLine(): void
    {
        if ($this->isSiteOrder() || ! $this->user()->can('orders.price-override')) {
            return;
        }

        $this->form->items[] = ['uid' => 'n'.Str::random(10), 'id' => null, 'product_id' => null, 'name' => '', 'sku' => null, 'unit' => null, 'qty' => 1, 'price' => '', 'roll_type_id' => null, 'labels_per_roll' => null, 'core_diameter_mm' => null, 'label_size' => null, 'price_per_label' => '', 'edited' => true];
    }

    public function removeItem(int $index): void
    {
        unset($this->form->items[$index]);
        $this->form->items = array_values($this->form->items);
    }

    public function save(CreateManualOrder $createOrder, UpdateOrder $updateOrder): void
    {
        $user = $this->user();
        $this->form->validate();

        if ($this->order === null) {
            $this->authorize('create', Order::class);

            if ($this->form->is_test) {
                $this->authorize('createTest', Order::class);
            }

            $order = $this->runFormAction(fn (): Order => $createOrder->handle($this->form->manualOrderData(), $user));
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('orders.toast.created', ['number' => $order->number]), 'type' => 'success']);
            $this->redirectRoute('admin.orders.show', $order, navigate: true);

            return;
        }

        $this->authorize('update', $this->order);
        $order = $this->order;
        $customer = ctype_digit($this->form->customer_id) ? Customer::query()->find((int) $this->form->customer_id) : null;

        // One transaction, one recalc, one matrix check, one invoice reissue (UpdateOrder).
        $this->runFormAction(fn () => $updateOrder->handle(
            $order,
            $this->form->detailsData(),
            $customer,
            $this->form->deliveryData(),
            $this->form->lineData($order->source),
            $this->form->payment_method,
            $user,
        ));

        session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('orders.toast.saved', ['number' => $order->number]), 'type' => 'success']);
        $this->redirectRoute('admin.orders.show', $order, navigate: true);
    }

    public function render(CheckoutSettings $settings, PaymentOptions $paymentOptions): View
    {
        $title = $this->order ? __('orders.edit_title', ['number' => $this->order->number]) : __('orders.new');
        $deliveryType = DeliveryType::tryFrom($this->form->delivery_type) ?? DeliveryType::Pickup;
        $region = Region::tryFrom($this->form->region);
        $fee = $this->form->deliveryFeeInTiyin();
        $quote = $deliveryType === DeliveryType::CourierRegion && $fee !== null ? $fee : DeliveryQuote::for($deliveryType, $region, $settings)->amount;
        $goods = $this->form->goodsTotal();
        $total = $goods + $quote;
        // IKPU is checked by the action on the saved lines; here the matrix only.
        $context = new PaymentContext($deliveryType, BuyerType::tryFrom($this->form->buyer_type) ?? BuyerType::Individual, $total, true, $this->form->is_test);
        $methods = $paymentOptions->for($context);

        // The saved method stays listed (the action says why it can't be kept).
        if ($this->order !== null && ! in_array($this->order->payment_method, $methods, true)) {
            $methods[] = $this->order->payment_method;
        }

        return view('livewire.admin.orders.form', [
            'title' => $title,
            'goods' => $goods,
            'deliveryAmount' => $quote,
            'deliverySeparate' => $deliveryType === DeliveryType::CourierRegion && $fee === null && DeliveryQuote::for($deliveryType, $region, $settings)->isSeparate(),
            'total' => $total,
            'vat' => Vat::of($total),
            'methods' => $methods,
            'isSite' => $this->isSiteOrder(),
            'detailsEditable' => $this->order === null || $this->order->isDetailsEditable(),
            'deliveryEditable' => $this->order === null || $this->order->isDeliveryEditable(),
            'linesEditable' => $this->order === null || $this->order->isLinesEditable(),
            'canTest' => $this->order === null && $this->user()->can('createTest', Order::class),
            'canFreeLine' => ! $this->isSiteOrder() && $this->user()->can('orders.price-override'),
        ])->title($title);
    }

    private function isSiteOrder(): bool
    {
        return $this->order?->source === OrderSource::Site;
    }

    /**
     * New product lines follow the current price level.
     */
    private function repriceNewLines(): void
    {
        $products = Product::query()
            ->whereIn('id', collect($this->form->items)->filter(fn (array $item): bool => ($item['id'] ?? null) === null)->pluck('product_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        foreach ($this->form->items as $index => $item) {
            if (($item['id'] ?? null) === null && ($item['product_id'] ?? null) !== null) {
                $levelPrice = Money::tiyinToSums($products->get($item['product_id'])?->priceFor($this->priceLevel) ?? 0);
                $this->form->items[$index][OrderForm::isRollRow($item) ? 'price_per_label' : 'price'] = $levelPrice;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(Product $product, int $qty): array
    {
        $levelPrice = Money::tiyinToSums($product->priceFor($this->priceLevel) ?? 0);
        $row = [
            'uid' => 'n'.Str::random(10),
            'id' => null,
            'product_id' => $product->id,
            'name' => $product->getTranslation('name', $this->form->locale),
            'sku' => $product->sku,
            'unit' => $product->unit->value,
            'qty' => $qty,
            'price' => $levelPrice,
            'roll_type_id' => null,
            'labels_per_roll' => null,
            'core_diameter_mm' => null,
            'label_size' => null,
            'price_per_label' => '',
            'edited' => false,
        ];

        if (! $product->isPricedPerLabel()) {
            return $row;
        }

        $rollType = $product->rollTypes->first(fn (RollType $type): bool => $type->is_active);

        return [
            ...$row,
            'unit' => 'roll',
            'price' => '',
            'roll_type_id' => $rollType?->id,
            'labels_per_roll' => $rollType?->labels_per_roll ?? 0,
            'core_diameter_mm' => $rollType?->core_diameter_mm,
            'label_size' => RollPricing::labelSize($product),
            'price_per_label' => $levelPrice,
        ];
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
