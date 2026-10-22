<?php

namespace App\Actions\Leads;

use App\Data\Leads\LeadItemData;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Write a lead's items in the given order (used inside CreateLead /
 * UpdateLead transactions). New rows snapshot the product's SKU and its
 * name in the lead's locale; existing rows (itemId) keep their snapshots
 * and only change qty, note and order; rows left out are deleted.
 */
class SyncLeadItems
{
    public const MAX_ITEMS = 50;

    public const MAX_QTY = 1_000_000;

    /**
     * @param  list<LeadItemData>  $items
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, array $items): void
    {
        if (count($items) > self::MAX_ITEMS) {
            throw ValidationException::withMessages(['items' => __('crm.leads.errors.too_many_items', ['max' => self::MAX_ITEMS])]);
        }

        $existing = LeadItem::query()->where('lead_id', $lead->id)->get()->keyBy('id');

        $productIds = array_values(array_unique(array_filter(array_map(
            fn (LeadItemData $item): ?int => $item->itemId === null ? $item->productId : null,
            $items,
        ))));

        $products = Product::query()->whereKey($productIds)->get(['id', 'sku', 'name'])->keyBy('id');
        $kept = [];

        foreach (array_values($items) as $sort => $item) {
            $qty = max(1, min(self::MAX_QTY, $item->qty));
            $note = filled($item->note) ? mb_substr(trim((string) $item->note), 0, 500) : null;

            if ($item->itemId !== null) {
                $row = $existing->get($item->itemId);

                if (! $row instanceof LeadItem) {
                    throw ValidationException::withMessages(['items' => __('crm.leads.errors.item_missing')]);
                }

                $row->fill(['qty' => $qty, 'note' => $note, 'sort' => $sort])->save();
                $kept[] = $row->id;

                continue;
            }

            $product = $item->productId !== null ? $products->get($item->productId) : null;

            if (! $product instanceof Product) {
                throw ValidationException::withMessages(['items' => __('crm.leads.errors.product_missing')]);
            }

            $kept[] = $lead->items()->create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'product_name' => mb_substr((string) $product->getTranslation('name', $lead->locale), 0, 255),
                'qty' => $qty,
                'note' => $note,
                'sort' => $sort,
            ])->id;
        }

        LeadItem::query()->where('lead_id', $lead->id)->whereNotIn('id', $kept)->delete();
    }
}
