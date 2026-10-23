<?php

namespace App\Models;

use App\Enums\ProductUnit;
use Database\Factories\QuoteItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quote line (plan 06). name / sku / unit are snapshots; price is per
 * unit in tiyin including VAT; total = price × qty (SaveQuoteItems).
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
 */
#[Fillable(['quote_id', 'product_id', 'name', 'sku', 'unit', 'qty', 'price', 'total', 'sort'])]
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
     * Trashed products too (the admin link still works from the trash).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
