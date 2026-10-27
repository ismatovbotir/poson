<?php

namespace App\Actions\Quotes;

use App\Data\Catalog\RollLine;
use App\Data\Quotes\QuoteItemData;
use App\Enums\ProductUnit;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RollType;
use App\Support\Catalog\RollPricing;
use Illuminate\Validation\ValidationException;

/**
 * Write a quote's lines in order and recompute its totals (inside the
 * CreateQuote / UpdateQuote transactions). Product lines snapshot name (in
 * the quote's locale), SKU and unit; their default price is the customer's
 * level price (Dealer / Wholesale fall back to Retail). total = price × qty;
 * the quote's subtotal = total = sum of lines, vat_amount = its VAT part.
 *
 * Limits are checked, never clamped: a price above MAX_PRICE, a qty above
 * MAX_QTY, or a line / running total above MAX_TOTAL is a validation error.
 * With these caps every intermediate value stays below PHP_INT_MAX: a line
 * is at most MAX_PRICE × MAX_QTY = 1e17, the running total at most
 * MAX_TOTAL + one line = 2e17, and the VAT formula multiplies the total by
 * 24 (≤ 2.4e18 < 9.2e18).
 *
 * Label lines (plan 08): qty = rolls (≥ 1; below the product's min_qty is
 * only a warning in the form), price = per roll = price per label × labels
 * per roll (RollPricing), total = price × qty; the per-label price must be
 * whole soʻm and MAX_PRICE / MAX_TOTAL apply to the per-roll price and the
 * total. A new label product line needs an attached, active roll type
 * (otherwise it is rejected); the roll snapshot (roll_type_id,
 * labels_per_roll, core_diameter_mm, label_size, price_per_label) is taken
 * then and kept on later saves, where only the rolls and the per-label
 * price change.
 */
class SaveQuoteItems
{
    public const MAX_ITEMS = 100;

    public const MAX_QTY = 10_000;

    /**
     * Highest unit price, tiyin (100 billion soʻm).
     */
    public const MAX_PRICE = 10_000_000_000_000;

    /**
     * Highest line and quote total, tiyin (1 trillion soʻm).
     */
    public const MAX_TOTAL = 100_000_000_000_000_000;

    /**
     * @param  list<QuoteItemData>  $items
     *
     * @throws ValidationException
     */
    public function handle(Quote $quote, array $items): void
    {
        if (count($items) > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['items' => __('crm.quotes.errors.too_many_items', ['max' => self::MAX_ITEMS])]);
        }

        $existing = QuoteItem::query()->where('quote_id', $quote->id)->get()->keyBy('id');
        $products = Product::query()
            ->whereKey(array_values(array_filter(array_map(fn (QuoteItemData $item): ?int => $item->itemId === null ? $item->productId : null, $items))))
            ->with('rollTypes')
            ->get()
            ->keyBy('id');

        // Roll types of copied roll lines that still exist (one query; the rest keep a null roll_type_id).
        $existingRollTypeIds = RollType::query()
            ->whereKey(array_values(array_filter(array_map(fn (QuoteItemData $item): ?int => $item->rollSnapshot?->rollTypeId, $items))))
            ->pluck('id')
            ->all();

        $kept = [];
        $sum = 0;

        foreach (array_values($items) as $sort => $item) {
            if ($item->qty < 1 || $item->qty > self::MAX_QTY) {
                throw ValidationException::withMessages(["items.{$sort}.qty" => __('validation.between.numeric', ['attribute' => __('crm.quotes.items.qty'), 'min' => 1, 'max' => self::MAX_QTY])]);
            }

            if ($item->price !== null && ($item->price < 0 || $item->price > self::MAX_PRICE)) {
                throw ValidationException::withMessages(["items.{$sort}.price" => __('crm.quotes.errors.price_too_high')]);
            }

            $qty = $item->qty;
            $price = $item->price;

            if ($item->itemId !== null) {
                $row = $existing->get($item->itemId);

                if (! $row instanceof QuoteItem) {
                    throw ValidationException::withMessages(['items' => __('crm.quotes.errors.item_missing')]);
                }

                if ($row->product_id === null && filled($item->name)) {
                    $row->name = mb_substr(trim((string) $item->name), 0, 255);
                }

                if ($row->isRollLine()) {
                    $price = $this->rollPrice($row, $item->pricePerLabel ?? $row->price_per_label ?? 0, $sort);
                }
            } elseif ($item->productId !== null) {
                $product = $products->get($item->productId);

                if (! $product instanceof Product) {
                    throw ValidationException::withMessages(['items' => __('crm.quotes.errors.product_missing')]);
                }

                $row = new QuoteItem([
                    'product_id' => $product->id,
                    'name' => mb_substr((string) $product->getTranslation('name', $quote->locale), 0, 255),
                    'sku' => $product->sku,
                    'unit' => $product->unit,
                ]);

                if ($product->isPricedPerLabel()) {
                    $line = $this->rollLine($product, $item, $quote, $sort);
                    $row->fill([...$line->snapshot(), 'unit' => ProductUnit::Roll]);
                    $price = $line->rollPrice;
                } else {
                    $price ??= (int) $product->priceFor($quote->price_level);
                }
            } elseif ($item->rollSnapshot !== null) {
                $name = trim((string) $item->name);

                if ($name === '') {
                    throw ValidationException::withMessages(["items.{$sort}.name" => __('validation.required', ['attribute' => __('crm.quotes.items.name')])]);
                }

                $row = new QuoteItem([
                    'name' => mb_substr($name, 0, 255),
                    'unit' => ProductUnit::Roll,
                    ...$this->snapshotOf($item->rollSnapshot, $existingRollTypeIds),
                ]);
                $price = $this->rollPrice($row, $item->rollSnapshot->pricePerLabel ?? 0, $sort);
            } else {
                $name = trim((string) $item->name);

                if ($name === '') {
                    throw ValidationException::withMessages(["items.{$sort}.name" => __('validation.required', ['attribute' => __('crm.quotes.items.name')])]);
                }

                $row = new QuoteItem(['name' => mb_substr($name, 0, 255)]);
            }

            $row->quote_id = $quote->id;
            $row->qty = $qty;
            $row->price = $price ?? (int) $row->price;

            if ($row->price > self::MAX_PRICE) {
                throw ValidationException::withMessages(["items.{$sort}.price" => __('crm.quotes.errors.price_too_high')]);
            }

            $row->total = $row->price * $qty;

            if ($row->total > self::MAX_TOTAL || $sum + $row->total > self::MAX_TOTAL) {
                throw ValidationException::withMessages(['items' => __('crm.quotes.errors.total_too_high')]);
            }

            $row->sort = $sort;
            $row->save();

            $kept[] = $row->id;
            $sum += $row->total;
        }

        QuoteItem::query()->where('quote_id', $quote->id)->whereNotIn('id', $kept)->delete();

        $quote->forceFill([
            'subtotal' => $sum,
            'total' => $sum,
            'vat_amount' => Quote::vatOf($sum),
        ])->save();
    }

    /**
     * The roll line of a new label product line; RollPricing errors re-keyed to the line.
     *
     * @throws ValidationException
     */
    private function rollLine(Product $product, QuoteItemData $item, Quote $quote, int $sort): RollLine
    {
        $rollType = $item->rollTypeId !== null ? $product->rollTypes->firstWhere('id', $item->rollTypeId) : null;

        if (! $rollType instanceof RollType) {
            throw ValidationException::withMessages(["items.{$sort}.roll_type_id" => __($item->rollTypeId === null ? 'catalog.roll.errors.required' : 'catalog.roll.errors.unavailable')]);
        }

        $this->checkPricePerLabel($item->pricePerLabel, $sort);

        try {
            return RollPricing::line($product, $rollType, $item->qty, $item->pricePerLabel ?? ($product->priceFor($quote->price_level) ?? 0), $quote->price_level);
        } catch (ValidationException $exception) {
            $keys = ['roll_type' => 'roll_type_id', 'rolls' => 'qty', 'price' => 'price_per_label'];

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => ["items.{$sort}.".($keys[$key] ?? $key) => $messages])
                ->all());
        }
    }

    /**
     * Per-roll price of a saved (or copied) roll line from a per-label price;
     * the per-label price is stored on the row.
     *
     * @throws ValidationException
     */
    private function rollPrice(QuoteItem $row, int $pricePerLabel, int $sort): int
    {
        $this->checkPricePerLabel($pricePerLabel, $sort);

        $labelsPerRoll = (int) $row->labels_per_roll;

        // Checked before multiplying: the per-roll price must stay within MAX_PRICE.
        if ($labelsPerRoll > 0 && $pricePerLabel > intdiv(self::MAX_PRICE, $labelsPerRoll)) {
            throw ValidationException::withMessages(["items.{$sort}.price_per_label" => __('crm.quotes.errors.price_too_high')]);
        }

        $row->price_per_label = $pricePerLabel;

        return RollPricing::rollPrice($pricePerLabel, $labelsPerRoll);
    }

    /**
     * @throws ValidationException
     */
    private function checkPricePerLabel(?int $pricePerLabel, int $sort): void
    {
        if ($pricePerLabel === null) {
            return;
        }

        if ($pricePerLabel < 0 || $pricePerLabel > self::MAX_PRICE) {
            throw ValidationException::withMessages(["items.{$sort}.price_per_label" => __('crm.quotes.errors.price_too_high')]);
        }

        if (! RollPricing::isWholeSom($pricePerLabel)) {
            throw ValidationException::withMessages(["items.{$sort}.price_per_label" => __('catalog.roll.errors.whole_som')]);
        }
    }

    /**
     * Snapshot columns of a copied roll line.
     *
     * @param  list<int>  $existingRollTypeIds
     * @return array<string, mixed>
     */
    private function snapshotOf(RollLine $line, array $existingRollTypeIds): array
    {
        return [
            'roll_type_id' => in_array($line->rollTypeId, $existingRollTypeIds, true) ? $line->rollTypeId : null,
            'labels_per_roll' => $line->labelsPerRoll,
            'core_diameter_mm' => $line->coreDiameterMm,
            'label_size' => $line->labelSize,
        ];
    }
}
