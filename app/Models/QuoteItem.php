<?php

namespace App\Models;

use App\Enums\ProductUnit;
use App\Support\Catalog\RollPricing;
use Database\Factories\QuoteItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quote line (plan 06). name / sku / unit are snapshots; price is per
 * unit in tiyin including VAT; total = price × qty (SaveQuoteItems).
 *
 * A label line (plan 08) snapshots its roll: qty = rolls, price = per roll =
 * price_per_label × labels_per_roll (price_per_label is the source of
 * truth), label_size "100×150" from the product. Later product or roll
 * type edits never change these columns.
 *
 * @property int $id
 * @property int $quote_id
 * @property int|null $product_id
 * @property string $name
 * @property string|null $sku
 * @property ProductUnit|null $unit
 * @property int $qty
 * @property int $price
 * @property int $total
 * @property int $sort
 * @property int|null $roll_type_id
 * @property int|null $labels_per_roll
 * @property string|null $core_diameter_mm
 * @property string|null $label_size
 * @property int|null $price_per_label
 */
#[Fillable([
    'quote_id', 'product_id', 'name', 'sku', 'unit', 'qty', 'price', 'total', 'sort',
    'roll_type_id', 'labels_per_roll', 'core_diameter_mm', 'label_size', 'price_per_label',
])]
class QuoteItem extends Model
{
    /** @use HasFactory<QuoteItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => ProductUnit::class,
            'qty' => 'integer',
            'price' => 'integer',
            'total' => 'integer',
            'sort' => 'integer',
            'roll_type_id' => 'integer',
            'labels_per_roll' => 'integer',
            'core_diameter_mm' => 'decimal:1',
            'price_per_label' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * A label line sold in rolls (plan 08).
     */
    public function isRollLine(): bool
    {
        return $this->labels_per_roll !== null;
    }

    /**
     * "100×150 mm, 500 labels/roll, core 40 mm × 10 rolls" in a locale; null for other lines.
     */
    public function rollDescription(?string $locale = null, bool $withRolls = true): ?string
    {
        if (! $this->isRollLine()) {
            return null;
        }

        return RollPricing::describe($this->label_size, (int) $this->labels_per_roll, $this->core_diameter_mm, $withRolls ? $this->qty : null, $locale);
    }

    /**
     * Trashed products too (the admin link still works from the trash).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
