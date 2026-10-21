<?php

namespace App\Models;

use App\Enums\RelationType;
use Database\Factories\ProductRelationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed link product → related product with a type (accessory,
 * consumable, alternative, software).
 *
 * @property int $id
 * @property int $product_id
 * @property int $related_id
 * @property RelationType $type
 * @property int $sort
 */
#[Fillable(['product_id', 'related_id', 'type', 'sort'])]
class ProductRelation extends Model
{
    /** @use HasFactory<ProductRelationFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RelationType::class,
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The related product, also when it is in the trash (the admin marks it).
     *
     * @return BelongsTo<Product, $this>
     */
    public function related(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_id')->withTrashed();
    }
}
