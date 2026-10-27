<?php

namespace App\Actions\Catalog;

use App\Enums\ProductPricingUnit;
use App\Models\Product;
use App\Models\RollType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a label roll type (plan 08). Owns its invariants:
 * labels per roll 1..RollType::MAX_LABELS (integer), core diameter
 * RollType::MIN_CORE..MAX_CORE mm with at most one decimal, and the labels /
 * core pair is unique.
 *
 * Editing a roll type never touches quote or lead lines: they keep their
 * own snapshots (labels_per_roll, core_diameter_mm, price_per_label).
 */
class SaveRollType
{
    /**
     * @param  array{labels_per_roll: int, core_diameter_mm: string|float, is_active: bool, sort: int|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(?RollType $rollType, array $data): RollType
    {
        $rollType ??= new RollType;
        $labels = (int) $data['labels_per_roll'];
        $core = static::normalizeCore($data['core_diameter_mm']);
        $errors = [];

        if ($labels < RollType::MIN_LABELS || $labels > RollType::MAX_LABELS) {
            $errors['labels_per_roll'] = __('validation.between.numeric', ['attribute' => __('admin.roll_types.fields.labels_per_roll'), 'min' => RollType::MIN_LABELS, 'max' => RollType::MAX_LABELS]);
        }

        if ($core === null) {
            $errors['core_diameter_mm'] = __('admin.roll_types.errors.core_invalid');
        }

        if ($errors === [] && RollType::query()
            ->where('labels_per_roll', $labels)
            ->where('core_diameter_mm', $core)
            ->when($rollType->exists, fn ($query) => $query->whereKeyNot($rollType->id))
            ->exists()) {
            $errors['labels_per_roll'] = __('admin.roll_types.errors.duplicate');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $rollType->fill([
                'labels_per_roll' => $labels,
                'core_diameter_mm' => $core,
                'is_active' => $data['is_active'],
                'sort' => $data['sort'] ?? (int) RollType::query()->max('sort') + 1,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['labels_per_roll' => __('admin.roll_types.errors.duplicate')]);
        }

        return $rollType;
    }

    /**
     * Active label products the roll type is attached to that have no other
     * active roll type: hiding the roll type leaves them without one (the site
     * then takes their quote requests without a roll). Callers warn, never block.
     */
    public static function productsLeftWithoutRoll(RollType $rollType): int
    {
        return Product::query()
            ->where('products.is_active', true)
            ->where('products.pricing_unit', ProductPricingUnit::Label->value)
            ->whereHas('rollTypes', fn (Builder $query) => $query->whereKey($rollType->id))
            ->whereDoesntHave('rollTypes', fn (Builder $query) => $query->where('roll_types.is_active', true)->whereKeyNot($rollType->id))
            ->count();
    }

    /**
     * "40", "12,7", 12.7 → "40.0" / "12.7"; null when not a number in range
     * with at most one decimal.
     */
    public static function normalizeCore(string|float|int|null $value): ?string
    {
        $raw = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim((string) $value));

        if (! preg_match('/^\d{1,3}(\.\d)?$/', $raw)) {
            return null;
        }

        $tenths = (int) round(((float) $raw) * 10);

        if ($tenths < RollType::MIN_CORE * 10 || $tenths > (int) round(RollType::MAX_CORE * 10)) {
            return null;
        }

        return intdiv($tenths, 10).'.'.($tenths % 10);
    }
}
