<?php

namespace App\Actions\Leads;

use App\Data\Leads\LeadItemData;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\RollType;
use App\Support\Catalog\RollPricing;
use Illuminate\Validation\ValidationException;

/**
 * Write a lead's items in the given order (used inside CreateLead /
 * UpdateLead transactions). New rows snapshot the product's SKU and its
 * name in the lead's locale; existing rows (itemId) keep their snapshots
 * and only change qty, note and order; rows left out are deleted.
 *
 * A new row of a label product with a roll type (plan 08, site quote
 * requests) snapshots the roll (roll_type_id, labels_per_roll,
 * core_diameter_mm); the roll type must be attached to the product and
 * active. Without one (e.g. added in the admin lead form) the row has no roll.
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

        $products = Product::query()->whereKey($productIds)->with('rollTypes')->get(['id', 'sku', 'name', 'pricing_unit'])->keyBy('id');
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
                ...$this->rollSnapshot($product, $item->rollTypeId),
            ])->id;
        }

        LeadItem::query()->where('lead_id', $lead->id)->whereNotIn('id', $kept)->delete();
    }

    /**
     * Roll columns of a new row: empty without a roll type; the roll type must be offered for the product.
     *
     * @return array<string, int|string|null>
     *
     * @throws ValidationException
     */
    private function rollSnapshot(Product $product, ?int $rollTypeId): array
    {
        if ($rollTypeId === null) {
            return [];
        }

        $rollType = $product->rollTypes->firstWhere('id', $rollTypeId);

        if (! $product->isPricedPerLabel() || ! $rollType instanceof RollType || ! RollPricing::isAvailable($product, $rollType)) {
            throw ValidationException::withMessages(['items' => __('catalog.roll.errors.unavailable')]);
        }

        return [
            'roll_type_id' => $rollType->id,
            'labels_per_roll' => $rollType->labels_per_roll,
            'core_diameter_mm' => $rollType->core_diameter_mm,
        ];
    }
}
