<?php

namespace App\Support\Catalog;

use App\Data\Catalog\RollLine;
use App\Enums\PriceLevel;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\RollType;
use App\Support\NumberFormat;
use Illuminate\Validation\ValidationException;

/**
 * Roll maths of label products (plan 08), the one place that turns a
 * per-label price into roll lines (quotes, site leads, later the cart).
 *
 * Line invariant: qty = rolls; price per roll = price per label × labels per
 * roll; total = price per roll × rolls. Integer tiyin only; the per-label
 * price must be whole soʻm (divisible by 100). Callers apply their own
 * minimum / maximum rolls and money caps (SaveQuoteItems::MAX_PRICE, ...).
 *
 * Errors are ValidationExceptions keyed "roll_type", "rolls" and "price"
 * (callers re-key them to their fields).
 */
class RollPricing
{
    public const TIYIN_PER_SOM = 100;

    /**
     * Attribute codes of the label size snapshot.
     */
    public const WIDTH_CODE = 'label_width';

    public const HEIGHT_CODE = 'label_height';

    /**
     * Build a line for a label product in one of its roll types.
     *
     * @param  int|null  $pricePerLabel  tiyin; null = the product's price at $level (Dealer / Wholesale fall back to Retail)
     *
     * @throws ValidationException
     */
    public static function line(Product $product, RollType $rollType, int $rolls, ?int $pricePerLabel, PriceLevel $level): RollLine
    {
        if (! $product->isPricedPerLabel()) {
            throw ValidationException::withMessages(['roll_type' => __('catalog.roll.errors.not_label_product')]);
        }

        if (! static::isAvailable($product, $rollType)) {
            throw ValidationException::withMessages(['roll_type' => __('catalog.roll.errors.unavailable')]);
        }

        if ($rolls < 1) {
            throw ValidationException::withMessages(['rolls' => __('catalog.roll.errors.rolls')]);
        }

        $pricePerLabel ??= $product->priceFor($level);
        $labelsPerRoll = $rollType->labels_per_roll;

        if ($pricePerLabel !== null && ! static::isWholeSom($pricePerLabel)) {
            throw ValidationException::withMessages(['price' => __('catalog.roll.errors.whole_som')]);
        }

        $rollPrice = null;
        $total = null;

        if ($pricePerLabel !== null) {
            // Integer overflow guard (callers cap far below this).
            if ($labelsPerRoll > 0 && $pricePerLabel > intdiv(PHP_INT_MAX, $labelsPerRoll)) {
                throw ValidationException::withMessages(['price' => __('catalog.roll.errors.too_high')]);
            }

            $rollPrice = $pricePerLabel * $labelsPerRoll;

            if ($rollPrice > 0 && $rolls > intdiv(PHP_INT_MAX, $rollPrice)) {
                throw ValidationException::withMessages(['price' => __('catalog.roll.errors.too_high')]);
            }

            $total = $rollPrice * $rolls;
        }

        return new RollLine(
            productId: $product->id,
            rollTypeId: $rollType->id,
            rolls: $rolls,
            labelsPerRoll: $labelsPerRoll,
            coreDiameterMm: (string) $rollType->core_diameter_mm,
            labelSize: static::labelSize($product),
            pricePerLabel: $pricePerLabel,
            rollPrice: $rollPrice,
            total: $total,
        );
    }

    /**
     * Price per roll from a per-label price (tiyin).
     */
    public static function rollPrice(int $pricePerLabel, int $labelsPerRoll): int
    {
        return $pricePerLabel * $labelsPerRoll;
    }

    /**
     * Whole soʻm: non-negative tiyin divisible by 100.
     */
    public static function isWholeSom(int $tiyin): bool
    {
        return $tiyin >= 0 && $tiyin % self::TIYIN_PER_SOM === 0;
    }

    /**
     * The roll type is attached to the product and active. Uses the loaded
     * "rollTypes" relation when present, else one query.
     */
    public static function isAvailable(Product $product, RollType $rollType): bool
    {
        if (! $rollType->is_active) {
            return false;
        }

        if ($product->relationLoaded('rollTypes')) {
            return $product->rollTypes->contains(fn (RollType $attached): bool => $attached->id === $rollType->id && $attached->is_active);
        }

        return $product->activeRollTypes()->whereKey($rollType->id)->exists();
    }

    /**
     * "100×150" from the product's label_width / label_height values (locale
     * neutral, as stored); null when either is missing. Uses the loaded
     * "attributeValues.attribute" relation when present, else one query.
     */
    public static function labelSize(Product $product): ?string
    {
        if ($product->relationLoaded('attributeValues')) {
            $values = $product->attributeValues
                ->filter(fn (ProductAttributeValue $value): bool => in_array($value->getRelationValue('attribute')?->code, [self::WIDTH_CODE, self::HEIGHT_CODE], true))
                ->mapWithKeys(fn (ProductAttributeValue $value): array => [$value->getRelationValue('attribute')->code => $value->numberForDisplay()]);
        } else {
            $values = ProductAttributeValue::query()
                ->join('attributes', 'attributes.id', '=', 'product_attribute_values.attribute_id')
                ->where('product_attribute_values.product_id', $product->id)
                ->whereIn('attributes.code', [self::WIDTH_CODE, self::HEIGHT_CODE])
                ->get(['product_attribute_values.value_number', 'attributes.code'])
                ->mapWithKeys(fn (ProductAttributeValue $value): array => [$value->getAttribute('code') => $value->numberForDisplay()]);
        }

        $width = $values->get(self::WIDTH_CODE);
        $height = $values->get(self::HEIGHT_CODE);

        if (blank($width) || blank($height)) {
            return null;
        }

        return mb_substr($width.'×'.$height, 0, 20);
    }

    /**
     * The roll description of a line in a locale:
     * "100×150 mm, 500 labels/roll, core 40 mm × 10 rolls" (the size part is
     * left out without a size; without rolls the "× N rolls" part too).
     */
    public static function describe(?string $labelSize, int $labelsPerRoll, string|float|null $coreDiameterMm, ?int $rolls = null, ?string $locale = null): string
    {
        $text = RollType::describe($labelsPerRoll, $coreDiameterMm, $locale);

        if (filled($labelSize)) {
            $size = implode('×', array_map(fn (string $part): string => NumberFormat::format($part, $locale), explode('×', (string) $labelSize)));
            $text = __('catalog.roll.size', ['size' => $size], $locale).', '.$text;
        }

        if ($rolls !== null) {
            $text .= ' × '.trans_choice('catalog.roll.rolls', $rolls, ['count' => NumberFormat::format($rolls, $locale)], $locale);
        }

        return $text;
    }
}
