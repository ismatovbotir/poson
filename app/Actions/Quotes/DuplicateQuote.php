<?php

namespace App\Actions\Quotes;

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

        $live = Product::query()->whereIn('id', $quote->items->pluck('product_id')->filter()->unique()->values())->pluck('id')->all();

        $items = $quote->items->map(fn (QuoteItem $item): QuoteItemData => $item->product_id !== null && in_array($item->product_id, $live, true)
            ? new QuoteItemData(productId: $item->product_id, qty: $item->qty)
            : new QuoteItemData(name: $item->name, qty: $item->qty, price: $item->price))->values()->all();

        return $this->createQuote->handle(new QuoteData(
            customerId: $quote->customer_id,
            leadId: $quote->lead_id,
            items: $items,
            notes: $quote->notes,
            locale: $quote->locale,
        ), $actor, ['from_number' => $quote->number]);
    }
}
