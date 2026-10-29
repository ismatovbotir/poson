<?php

namespace App\Models;

use App\Enums\ProductUnit;
use App\Support\Catalog\RollPricing;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An order line (plan 11 v2 §2): snapshots taken when the order was placed
 * (or the line saved by staff). price is per unit in tiyin including VAT
 * 12%, total = price × qty. ikpu_code / package_code / vat_percent are the
 * fiscal snapshot for Payme / Click receipts.
 *
 * A label line (plan 08) snapshots its roll like a quote line: qty = rolls,
 * price = per roll = price_per_label × labels_per_roll.
 *
 * reference_price / price_edited: on a manual order, the customer-level
 * price the staff edit started from (security condition 26).
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $product_id
 * @property string $name
 * @property string|null $sku
 * @property ProductUnit|null $unit
 * @property int $qty
 * @property int $price
 * @property int $total
 * @property int|null $reference_price
 * @property bool $price_edited
 * @property string|null $ikpu_code
 * @property string|null $package_code
 * @property int $vat_percent
 * @property bool $was_on_order
 * @property int|null $roll_type_id
 * @property int|null $labels_per_roll
 * @property string|null $core_diameter_mm
 * @property string|null $label_size
 * @property int|null $price_per_label
 * @property int $sort
 */
#[Fillable([
    'order_id', 'product_id', 'name', 'sku', 'unit', 'qty', 'price', 'total', 'reference_price', 'price_edited',
    'ikpu_code', 'package_code', 'vat_percent', 'was_on_order',
    'roll_type_id', 'labels_per_roll', 'core_diameter_mm', 'label_size', 'price_per_label', 'sort',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
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
            'reference_price' => 'integer',
            'price_edited' => 'boolean',
            'vat_percent' => 'integer',
            'was_on_order' => 'boolean',
            'sort' => 'integer',
            'roll_type_id' => 'integer',
            'labels_per_roll' => 'integer',
            'core_diameter_mm' => 'decimal:1',
            'price_per_label' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * The staff-set price is below the customer-level reference price.
     */
    public function isBelowReference(): bool
    {
        return $this->price_edited && $this->reference_price !== null && $this->price < $this->reference_price;
    }

    /**
     * Has the fiscal code needed for Payme / Click receipts.
     */
    public function hasIkpu(): bool
    {
        return is_string($this->ikpu_code) && preg_match('/^\d{17}$/', $this->ikpu_code) === 1;
    }
}
