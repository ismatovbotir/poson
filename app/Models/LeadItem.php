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
 * A label product asked for in rolls (plan 08) snapshots its roll:
 * roll_type_id, labels_per_roll, core_diameter_mm; qty is then rolls.
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $product_id
 * @property string|null $sku
 * @property string $product_name
 * @property int $qty
 * @property string|null $note
 * @property int $sort
 * @property int|null $roll_type_id
 * @property int|null $labels_per_roll
 * @property string|null $core_diameter_mm
 */
#[Fillable(['lead_id', 'product_id', 'sku', 'product_name', 'qty', 'note', 'sort', 'roll_type_id', 'labels_per_roll', 'core_diameter_mm'])]
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
            'roll_type_id' => 'integer',
            'labels_per_roll' => 'integer',
            'core_diameter_mm' => 'decimal:1',
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
     * Asked for in rolls (plan 08).
     */
    public function isRollLine(): bool
    {
        return $this->labels_per_roll !== null;
    }

    /**
     * "500 labels/roll, core 40 mm" in a locale; null without a roll.
     */
    public function rollName(?string $locale = null): ?string
    {
        return $this->isRollLine() ? RollType::describe((int) $this->labels_per_roll, $this->core_diameter_mm, $locale) : null;
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
