<?php

namespace App\Models;

use Database\Factories\LeadItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A product a lead asks about. sku and product_name are snapshots
 * (CreateLead / UpdateLead); product_id is null once the product is gone.
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $product_id
 * @property string|null $sku
 * @property string $product_name
 * @property int $qty
 * @property string|null $note
 * @property int $sort
 */
#[Fillable(['lead_id', 'product_id', 'sku', 'product_name', 'qty', 'note', 'sort'])]
class LeadItem extends Model
{
    /** @use HasFactory<LeadItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Trashed products too: the admin link still works while the product is in the trash.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
