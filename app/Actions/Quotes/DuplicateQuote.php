<?php

namespace App\Actions\Quotes;

use App\Data\Catalog\RollLine;
use App\Data\Quotes\QuoteData;
use App\Data\Quotes\QuoteItemData;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A new draft with the same customer, lead, locale, notes and lines, a new
 * number and a fresh 5-working-day validity.
 *
 * Product lines are re-snapshotted and re-priced from the customer's
 * current price level (the copy is a new offer at today's prices; the
 * manager edits from there). A line whose product is gone (deleted or in
 * the trash) stays as free text with its old name and price.
 *
 * Label lines (plan 08) keep their roll: with the product live and the roll
 * type still attached, active and unchanged (labels per roll, core), the
 * line is re-snapshotted in the same roll type at today's per-label price;
 * otherwise it is copied as a
 * free-text roll line with its old name, roll columns and per-label price.
 */
class DuplicateQuote
{
    public function __construct(protected CreateQuote $createQuote) {}

    /**
     * @throws ValidationException
     */
    public function handle(Quote $quote, User $actor): Quote
    {
        $quote->loadMissing('items');

        $live = Product::query()
            ->whereIn('id', $quote->items->pluck('product_id')->filter()->unique()->values())
            ->with('rollTypes')
            ->get()
            ->keyBy('id');

        $items = $quote->items->map(fn (QuoteItem $item): QuoteItemData => $this->copy($item, $item->product_id !== null ? $live->get($item->product_id) : null))->values()->all();

        return $this->createQuote->handle(new QuoteData(
            customerId: $quote->customer_id,
            leadId: $quote->lead_id,
            items: $items,
            notes: $quote->notes,
            locale: $quote->locale,
        ), $actor, ['from_number' => $quote->number]);
    }

    private function copy(QuoteItem $item, ?Product $product): QuoteItemData
    {
        if (! $item->isRollLine()) {
            // A product that became a label product since needs a roll: copied as free text.
            return $product !== null && ! $product->isPricedPerLabel()
                ? new QuoteItemData(productId: $product->id, qty: $item->qty)
                : new QuoteItemData(name: $item->name, qty: $item->qty, price: $item->price);
        }

        // Same roll type, still offered and still the same roll (labels and core as in the snapshot).
        $rollAvailable = $product !== null
            && $product->isPricedPerLabel()
            && $product->rollTypes->contains(fn ($rollType): bool => $rollType->id === $item->roll_type_id
                && $rollType->is_active
                && $rollType->labels_per_roll === $item->labels_per_roll
                && (string) $rollType->core_diameter_mm === (string) $item->core_diameter_mm);

        if ($rollAvailable) {
            return new QuoteItemData(productId: $product->id, qty: $item->qty, rollTypeId: $item->roll_type_id);
        }

        return new QuoteItemData(name: $item->name, qty: $item->qty, rollSnapshot: new RollLine(
            productId: null,
            rollTypeId: $item->roll_type_id,
            rolls: $item->qty,
            labelsPerRoll: (int) $item->labels_per_roll,
            coreDiameterMm: (string) $item->core_diameter_mm,
            labelSize: $item->label_size,
            pricePerLabel: $item->price_per_label,
            rollPrice: $item->price,
            total: $item->total,
        ));
    }
}
