<?php

namespace App\Models;

use App\Support\NumberFormat;
use Database\Factories\RollTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A label roll type (plan 08): labels per roll + core diameter. Label
 * products are sold in the roll types attached to them (product_roll_type,
 * attached = available); an inactive roll type is hidden everywhere. The
 * display name is built from a lang key (label()), never stored.
 *
 * Writes go through App\Actions\Catalog\SaveRollType / DeleteRollType.
 *
 * @property int $id
 * @property int $labels_per_roll
 * @property string $core_diameter_mm decimal(4,1) as a string, e.g. "40.0"
 * @property bool $is_active
 * @property int $sort
 */
#[Fillable(['labels_per_roll', 'core_diameter_mm', 'is_active', 'sort'])]
class RollType extends Model
{
    /** @use HasFactory<RollTypeFactory> */
    use HasFactory;

    public const MIN_LABELS = 1;

    public const MAX_LABELS = 100_000;

    /**
     * Core diameter limits in mm (decimal(4,1)).
     */
    public const MIN_CORE = 1;

    public const MAX_CORE = 999.9;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'labels_per_roll' => 'integer',
            'core_diameter_mm' => 'decimal:1',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('sort');
    }

    /**
     * @param  Builder<RollType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('roll_types.is_active', true);
    }

    /**
     * Picker / list order: core, then labels per roll.
     *
     * @param  Builder<RollType>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('roll_types.sort')->orderBy('roll_types.core_diameter_mm')->orderBy('roll_types.labels_per_roll');
    }

    /**
     * "1000 labels/roll, core 40 mm" in the given (or current) locale.
     */
    public function label(?string $locale = null): string
    {
        return static::describe($this->labels_per_roll, $this->core_diameter_mm, $locale);
    }

    /**
     * The roll name for any labels / core pair (also for quote and lead snapshots).
     */
    public static function describe(int $labelsPerRoll, string|float|null $coreDiameterMm, ?string $locale = null): string
    {
        return __('catalog.roll.name', [
            // Plural-aware for ru: "1 этикетка", "2 этикетки", "500 этикеток".
            'labels' => trans_choice('catalog.roll.labels', $labelsPerRoll, ['count' => NumberFormat::format($labelsPerRoll, $locale)], $locale),
            'core' => NumberFormat::format((string) ($coreDiameterMm ?? '0'), $locale),
        ], $locale);
    }

    /**
     * Attached to any product, trashed ones included (the pivot row blocks deleting).
     */
    public function isAttached(): bool
    {
        return DB::table('product_roll_type')->where('roll_type_id', $this->id)->exists();
    }
}
