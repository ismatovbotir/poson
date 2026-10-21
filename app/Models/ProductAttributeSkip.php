<?php

namespace App\Models;

use Database\Factories\ProductAttributeSkipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A category attribute a product deliberately does not use (composite key
 * product_id + attribute_id, no id column). Rows are written by SaveProduct.
 *
 * @property int $product_id
 * @property int $attribute_id
 */
#[Fillable(['product_id', 'attribute_id'])]
class ProductAttributeSkip extends Model
{
    /** @use HasFactory<ProductAttributeSkipFactory> */
    use HasFactory;

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'product_id';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'attribute_id' => 'integer',
        ];
    }

    /**
     * Updates and deletes of a single row match both key columns.
     *
     * @param  Builder<ProductAttributeSkip>  $query
     * @return Builder<ProductAttributeSkip>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query
            ->where('product_id', $this->getOriginal('product_id', $this->product_id))
            ->where('attribute_id', $this->getOriginal('attribute_id', $this->attribute_id));
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
