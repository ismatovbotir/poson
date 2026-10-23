<?php

namespace App\Actions\Quotes;

use App\Data\Quotes\QuoteItemData;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
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
            ->get()
            ->keyBy('id');

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
                $price ??= (int) $product->priceFor($quote->price_level);
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
}
