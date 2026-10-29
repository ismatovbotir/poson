<?php

namespace App\Livewire\Forms\Admin\Orders;

use App\Actions\Orders\SaveOrderItems;
use App\Actions\Quotes\SaveQuoteItems;
use App\Data\Orders\ManualOrderData;
use App\Data\Orders\OrderDeliveryData;
use App\Data\Orders\OrderDetailsData;
use App\Data\Orders\OrderLineData;
use App\Enums\OrderSource;
use App\Models\Order;
use App\Support\Money;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * State of the admin order form (plan 11b): buyer, delivery, payment method
 * and lines of a manual order (create) or of any order (edit, within the
 * editing rules the actions enforce). Prices are typed in whole soʻm (VAT
 * included) and converted to tiyin here; site orders never send prices
 * (they are priced from the catalog).
 *
 * Each row carries a stable "uid" for its wire:key (code review #13).
 *
 * @phpstan-type ItemRow array{uid: string, id: int|null, product_id: int|null, name: string, sku: string|null, unit: string|null, qty: int|string, price: string, roll_type_id: int|string|null, labels_per_roll: int|null, core_diameter_mm: string|null, label_size: string|null, price_per_label: string, edited: bool}
 */
class OrderForm extends Form
{
    public string $customer_id = '';

    public bool $is_test = false;

    public string $locale = 'uz';

    public string $buyer_type = 'individual';

    public string $name = '';

    public string $phone = '';

    public string $company_name = '';

    public string $tin = '';

    public string $comment = '';

    public string $staff_note = '';

    public string $manager_id = '';

    public string $delivery_type = 'pickup';

    public string $region = '';

    public string $address = '';

    public bool $kokand_confirmed = false;

    /**
     * Fixed courier fee for a region order in whole soʻm ('' = from Settings → Checkout).
     */
    public string $delivery_fee = '';

    public string $payment_method = 'on_receipt';

    /**
     * @var list<ItemRow>
     */
    public array $items = [];

    public function setOrder(Order $order): void
    {
        $order->loadMissing('items');

        $this->customer_id = (string) ($order->customer_id ?? '');
        $this->is_test = $order->is_test;
        $this->locale = $order->locale;
        $this->buyer_type = $order->buyer_type->value;
        $this->name = $order->name;
        $this->phone = $order->phone;
        $this->company_name = (string) $order->company_name;
        $this->tin = (string) $order->tin;
        $this->comment = (string) $order->comment;
        $this->staff_note = (string) $order->staff_note;
        $this->manager_id = (string) ($order->manager_id ?? '');
        $this->delivery_type = $order->delivery_type->value;
        $this->region = (string) $order->region?->value;
        $this->address = (string) $order->address;
        $this->kokand_confirmed = $order->kokand_address_confirmed;
        $this->delivery_fee = '';
        $this->payment_method = $order->payment_method->value;
        $this->items = $order->items->map(fn ($item): array => [
            'uid' => 'i'.$item->id,
            'id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->name,
            'sku' => $item->sku,
            'unit' => $item->unit?->value,
            'qty' => $item->qty,
            'price' => Money::tiyinToSums($item->price),
            'roll_type_id' => $item->roll_type_id,
            'labels_per_roll' => $item->labels_per_roll,
            'core_diameter_mm' => $item->core_diameter_mm,
            'label_size' => $item->label_size,
            'price_per_label' => $item->isRollLine() ? Money::tiyinToSums($item->price_per_label) : '',
            'edited' => $item->price_edited,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function isRollRow(array $item): bool
    {
        return ($item['labels_per_roll'] ?? null) !== null;
    }

    /**
     * Per-unit (per-roll) price in tiyin of a row from its typed price (0 when empty / invalid).
     *
     * @param  array<string, mixed>  $item
     */
    public static function rowUnitPrice(array $item): int
    {
        if (static::isRollRow($item)) {
            $perLabel = Money::sumsToTiyin((string) ($item['price_per_label'] ?? '')) ?? 0;
            $labels = max(0, (int) $item['labels_per_roll']);

            return $labels > 0 && $perLabel > intdiv(SaveQuoteItems::MAX_PRICE, $labels) ? 0 : $perLabel * $labels;
        }

        return Money::sumsToTiyin((string) ($item['price'] ?? '')) ?? 0;
    }

    /**
     * Live goods total in tiyin (from the typed prices).
     */
    public function goodsTotal(): int
    {
        $sum = 0;

        foreach ($this->items as $item) {
            $sum += static::rowUnitPrice($item) * max(0, min(SaveOrderItems::MAX_QTY, (int) ($item['qty'] ?? 0)));
        }

        return $sum;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $price = function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && Money::sumsToTiyin((string) $value) === null) {
                $fail(__('orders.errors.price_range'));
            }
        };

        return [
            'customer_id' => ['nullable', 'integer'],
            'is_test' => ['boolean'],
            'locale' => ['required', Rule::in(config('app.supported_locales'))],
            'buyer_type' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'tin' => ['nullable', 'string', 'max:20'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'staff_note' => ['nullable', 'string', 'max:2000'],
            'manager_id' => ['nullable', 'integer'],
            'delivery_type' => ['required', 'string', 'max:20'],
            'region' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'kokand_confirmed' => ['boolean'],
            'delivery_fee' => ['nullable', 'string', 'max:20', $price],
            'payment_method' => ['required', 'string', 'max:20'],
            'items' => ['array', 'min:1', 'max:'.Order::MAX_LINES],
            'items.*' => ['array:uid,id,product_id,name,sku,unit,qty,price,roll_type_id,labels_per_roll,core_diameter_mm,label_size,price_per_label,edited'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:'.SaveOrderItems::MAX_QTY],
            'items.*.price' => ['nullable', 'string', 'max:24', $price],
            'items.*.roll_type_id' => ['nullable', 'integer'],
            'items.*.price_per_label' => ['nullable', 'string', 'max:24', $price],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'name' => __('orders.fields.name'),
            'phone' => __('orders.fields.phone'),
            'company_name' => __('orders.fields.company_name'),
            'tin' => __('orders.fields.tin'),
            'address' => __('orders.fields.address'),
            'delivery_fee' => __('orders.fields.delivery_fee'),
            'items' => __('orders.items.title'),
        ];

        foreach (array_keys($this->items) as $index) {
            $attributes["items.{$index}.qty"] = __('orders.items.qty');
            $attributes["items.{$index}.price"] = __('orders.items.price');
            $attributes["items.{$index}.price_per_label"] = __('orders.items.price_per_label');
        }

        return $attributes;
    }

    public function detailsData(): OrderDetailsData
    {
        return new OrderDetailsData(
            name: $this->name,
            phone: $this->phone,
            buyerType: $this->buyer_type,
            companyName: $this->company_name,
            tin: $this->tin,
            comment: $this->comment,
            staffNote: $this->staff_note,
            managerId: ctype_digit($this->manager_id) ? (int) $this->manager_id : null,
        );
    }

    public function deliveryData(bool $withFee = true): OrderDeliveryData
    {
        return new OrderDeliveryData(
            deliveryType: $this->delivery_type,
            region: $this->region,
            address: $this->address,
            kokandConfirmed: $this->kokand_confirmed,
            deliveryFee: $withFee ? $this->deliveryFeeInTiyin() : null,
        );
    }

    public function deliveryFeeInTiyin(): ?int
    {
        return filled($this->delivery_fee) ? Money::sumsToTiyin($this->delivery_fee) : null;
    }

    /**
     * @return list<OrderLineData>
     */
    public function lineData(OrderSource $source): array
    {
        $manual = $source !== OrderSource::Site;

        return array_map(fn (array $item): OrderLineData => new OrderLineData(
            productId: isset($item['product_id']) ? (int) $item['product_id'] : null,
            name: $item['name'] ?? null,
            qty: (int) ($item['qty'] ?? 1),
            price: $manual && ! static::isRollRow($item) && filled($item['price'] ?? null) ? Money::sumsToTiyin((string) $item['price']) : null,
            itemId: isset($item['id']) ? (int) $item['id'] : null,
            rollTypeId: static::isRollRow($item) && filled($item['roll_type_id'] ?? null) ? (int) $item['roll_type_id'] : null,
            pricePerLabel: $manual && static::isRollRow($item) && filled($item['price_per_label'] ?? null) ? Money::sumsToTiyin((string) $item['price_per_label']) : null,
        ), array_values($this->items));
    }

    public function manualOrderData(): ManualOrderData
    {
        return new ManualOrderData(
            details: $this->detailsData(),
            delivery: $this->deliveryData(),
            paymentMethod: $this->payment_method,
            lines: $this->lineData(OrderSource::Manual),
            customerId: ctype_digit($this->customer_id) ? (int) $this->customer_id : null,
            locale: $this->locale,
            isTest: $this->is_test,
        );
    }
}
