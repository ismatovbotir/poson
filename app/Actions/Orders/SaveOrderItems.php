<?php

namespace App\Actions\Orders;

use App\Actions\Quotes\SaveQuoteItems;
use App\Data\Catalog\RollLine;
use App\Data\Orders\OrderLineData;
use App\Enums\OrderSource;
use App\Enums\PriceLevel;
use App\Enums\ProductUnit;
use App\Enums\StockStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RollType;
use App\Models\User;
use App\Support\Catalog\RollPricing;
use App\Support\Orders\CheckoutSettings;
use Illuminate\Validation\ValidationException;

/**
 * Write an order's lines from staff input (CreateManualOrder,
 * UpdateOrderLines) inside the caller's transaction, under the order lock;
 * the caller recomputes the totals (RecalculateOrderTotals).
 *
 * Pricing (security conditions 6 and 26):
 * - site orders: prices always come from the catalog at Retail; staff only
 *   change quantities, add or remove lines (a typed price is ignored);
 * - manual orders: a new product line starts at the order's price level
 *   (Dealer / Wholesale fall back to Retail); staff may type another price
 *   (1 ≤ price ≤ MAX_PRICE). A price below that reference price by more
 *   than checkout.max_manual_discount_percent needs orders.price-override
 *   (Admin). Every edited line keeps reference_price + price_edited and
 *   each change is returned for the history (old / new / reference);
 *   free-text lines (no product) need orders.price-override too.
 *
 * Every line snapshots the IKPU / package code (Payme / Click receipts) and
 * VAT 12%. Caps: Order::MAX_LINES lines, qty 1..10 000.
 */
class SaveOrderItems
{
    public const MAX_QTY = 10_000;

    public function __construct(protected CheckoutSettings $settings) {}

    /**
     * @param  list<OrderLineData>  $lines
     * @return list<array{name: string, old: int|null, new: int, reference: int|null}> price edits for the history
     *
     * @throws ValidationException
     */
    public function handle(Order $order, array $lines, User $actor): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => __('orders.errors.no_items')]);
        }

        if (count($lines) > Order::MAX_LINES) {
            throw ValidationException::withMessages(['items' => __('orders.errors.too_many_items', ['max' => Order::MAX_LINES])]);
        }

        $manual = $order->source !== OrderSource::Site;
        $level = $manual ? $order->price_level : PriceLevel::Retail;
        $existing = OrderItem::query()->where('order_id', $order->id)->get()->keyBy('id');
        $products = Product::query()
            ->whereKey(array_values(array_filter(array_map(fn (OrderLineData $line): ?int => $line->itemId === null ? $line->productId : null, $lines))))
            ->with('rollTypes')
            ->get()
            ->keyBy('id');

        $kept = [];
        $edits = [];

        foreach (array_values($lines) as $sort => $line) {
            if ($line->qty < 1 || $line->qty > self::MAX_QTY) {
                throw ValidationException::withMessages(["items.{$sort}.qty" => __('validation.between.numeric', ['attribute' => __('orders.items.qty'), 'min' => 1, 'max' => self::MAX_QTY])]);
            }

            if ($line->itemId !== null) {
                $row = $existing->get($line->itemId);

                if (! $row instanceof OrderItem) {
                    throw ValidationException::withMessages(['items' => __('orders.errors.item_missing')]);
                }

                $old = $row->price;

                if ($manual) {
                    $requested = $row->isRollLine() && $line->pricePerLabel !== null
                        ? $this->rollPrice($row, $line->pricePerLabel, $sort)
                        : ($row->isRollLine() ? null : $line->price);

                    // The form shows whole soʻm: the stored price with its tiyin cut off is "unchanged".
                    if ($requested !== null && $requested !== $row->price && $requested !== intdiv($row->price, 100) * 100) {
                        $this->applyPrice($row, $requested, $row->reference_price ?? $row->price, $actor, $sort);
                        $edits[] = ['name' => $row->name, 'old' => $old, 'new' => $row->price, 'reference' => $row->reference_price];
                    }
                }
            } elseif ($line->productId !== null) {
                $product = $products->get($line->productId);

                if (! $product instanceof Product) {
                    throw ValidationException::withMessages(['items' => __('orders.errors.product_missing')]);
                }

                $row = $this->newProductRow($order, $product, $line, $level, $manual, $actor, $sort);

                if ($row->price_edited) {
                    $edits[] = ['name' => $row->name, 'old' => null, 'new' => $row->price, 'reference' => $row->reference_price];
                }
            } else {
                if (! $manual) {
                    throw ValidationException::withMessages(['items' => __('orders.errors.site_free_line')]);
                }

                if (! $actor->can('orders.price-override')) {
                    throw ValidationException::withMessages(["items.{$sort}.name" => __('orders.errors.free_line_admin')]);
                }

                $name = trim((string) $line->name);

                if ($name === '') {
                    throw ValidationException::withMessages(["items.{$sort}.name" => __('validation.required', ['attribute' => __('orders.items.name')])]);
                }

                $row = new OrderItem(['name' => mb_substr($name, 0, 255), 'unit' => null, 'vat_percent' => 12, 'price_edited' => true]);
                $row->price = $this->checkedPrice($line->price, $sort);
                $edits[] = ['name' => $row->name, 'old' => null, 'new' => $row->price, 'reference' => null];
            }

            $row->order_id = $order->id;
            $row->qty = $line->qty;
            $row->total = $row->price * $line->qty;
            $row->sort = $sort;
            $row->save();

            $kept[] = $row->id;
        }

        OrderItem::query()->where('order_id', $order->id)->whereNotIn('id', $kept)->delete();

        return $edits;
    }

    /**
     * A new product line, priced at $level (site orders: Retail, from the catalog only).
     *
     * @throws ValidationException
     */
    private function newProductRow(Order $order, Product $product, OrderLineData $line, PriceLevel $level, bool $manual, User $actor, int $sort): OrderItem
    {
        if (! $manual && ($product->trashed() || ! $product->isVisible())) {
            throw ValidationException::withMessages(["items.{$sort}" => __('orders.errors.product_unavailable')]);
        }

        $row = new OrderItem([
            'product_id' => $product->id,
            'name' => mb_substr((string) $product->getTranslation('name', $order->locale), 0, 255),
            'sku' => $product->sku,
            'unit' => $product->unit,
            'ikpu_code' => $product->ikpu_code,
            'package_code' => $product->package_code,
            'vat_percent' => 12,
            'was_on_order' => $product->stock_status === StockStatus::OnOrder,
        ]);

        if ($product->isPricedPerLabel()) {
            $rollLine = $this->rollLine($product, $line, $level, $sort);
            $row->fill([...$rollLine->snapshot(), 'unit' => ProductUnit::Roll]);
            $reference = RollPricing::rollPrice((int) $product->priceFor($level), $rollLine->labelsPerRoll);
            $price = (int) $rollLine->rollPrice;

            if ($manual && $line->pricePerLabel !== null && $price !== $reference) {
                $this->applyPrice($row, $price, $reference, $actor, $sort);
            } else {
                $row->price = $this->checkedPrice($reference, $sort);
                $row->price_per_label = (int) $product->priceFor($level);
            }

            return $row;
        }

        $reference = $product->priceFor($level);

        if ($reference === null || $reference <= 0) {
            if (! $manual || $line->price === null) {
                throw ValidationException::withMessages(["items.{$sort}.price" => __('orders.errors.price_required')]);
            }

            $this->applyPrice($row, $this->checkedPrice($line->price, $sort), null, $actor, $sort);

            return $row;
        }

        if ($manual && $line->price !== null && $line->price !== $reference) {
            $this->applyPrice($row, $line->price, $reference, $actor, $sort);
        } else {
            $row->price = $this->checkedPrice($reference, $sort);
        }

        return $row;
    }

    /**
     * Set a staff-typed price: 1..MAX_PRICE; more than the allowed percentage
     * below the reference (or without one) needs orders.price-override.
     *
     * @throws ValidationException
     */
    private function applyPrice(OrderItem $row, int $price, ?int $reference, User $actor, int $sort): void
    {
        $price = $this->checkedPrice($price, $sort);

        if (! $actor->can('orders.price-override')) {
            $floor = $reference === null ? null : intdiv($reference * (100 - $this->settings->maxManualDiscountPercent()) + 99, 100);

            if ($floor === null || $price < $floor) {
                throw ValidationException::withMessages(["items.{$sort}.price" => __('orders.errors.price_below_floor')]);
            }
        }

        $row->price = $price;
        $row->reference_price = $reference;
        $row->price_edited = true;
    }

    /**
     * @throws ValidationException
     */
    private function checkedPrice(?int $price, int $sort): int
    {
        if ($price === null || $price < 1 || $price > SaveQuoteItems::MAX_PRICE) {
            throw ValidationException::withMessages(["items.{$sort}.price" => __('orders.errors.price_range')]);
        }

        return $price;
    }

    /**
     * @throws ValidationException
     */
    private function rollLine(Product $product, OrderLineData $line, PriceLevel $level, int $sort): RollLine
    {
        $rollType = $line->rollTypeId !== null ? $product->rollTypes->firstWhere('id', $line->rollTypeId) : null;

        if (! $rollType instanceof RollType) {
            throw ValidationException::withMessages(["items.{$sort}.roll_type_id" => __($line->rollTypeId === null ? 'catalog.roll.errors.required' : 'catalog.roll.errors.unavailable')]);
        }

        if ($line->pricePerLabel !== null && ($line->pricePerLabel < 1 || ! RollPricing::isWholeSom($line->pricePerLabel) || $line->pricePerLabel > intdiv(SaveQuoteItems::MAX_PRICE, max(1, $rollType->labels_per_roll)))) {
            throw ValidationException::withMessages(["items.{$sort}.price_per_label" => __('orders.errors.price_range')]);
        }

        try {
            return RollPricing::line($product, $rollType, $line->qty, $line->pricePerLabel, $level);
        } catch (ValidationException) {
            throw ValidationException::withMessages(["items.{$sort}.roll_type_id" => __('catalog.roll.errors.unavailable')]);
        }
    }

    /**
     * A saved label line's per-roll price from a new per-label price (stored on the row).
     *
     * @throws ValidationException
     */
    private function rollPrice(OrderItem $row, int $pricePerLabel, int $sort): int
    {
        $labels = max(1, (int) $row->labels_per_roll);

        if ($pricePerLabel < 1 || ! RollPricing::isWholeSom($pricePerLabel) || $pricePerLabel > intdiv(SaveQuoteItems::MAX_PRICE, $labels)) {
            throw ValidationException::withMessages(["items.{$sort}.price_per_label" => __('orders.errors.price_range')]);
        }

        $row->price_per_label = $pricePerLabel;

        return RollPricing::rollPrice($pricePerLabel, $labels);
    }
}
