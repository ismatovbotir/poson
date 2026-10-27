<?php

use App\Enums\PriceLevel;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\RollType;
use App\Support\Catalog\RollPricing;
use Illuminate\Validation\ValidationException;

/**
 * Validation error keys RollPricing throws (empty when it passes).
 *
 * @return list<string>
 */
function rollPricingErrorKeys(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }

    return [];
}

it('builds a roll line with integer maths: per roll = per label × labels, total = per roll × rolls', function () {
    $roll = RollType::factory()->size(1000, '40.0')->create();
    $product = Product::factory()->perLabel(12)->withRollTypes([$roll])->create();

    $line = RollPricing::line($product, $roll, 10, null, PriceLevel::Retail);

    expect($line->productId)->toBe($product->id)
        ->and($line->rollTypeId)->toBe($roll->id)
        ->and($line->rolls)->toBe(10)
        ->and($line->labelsPerRoll)->toBe(1000)
        ->and($line->coreDiameterMm)->toBe('40.0')
        ->and($line->pricePerLabel)->toBe(1_200)
        ->and($line->rollPrice)->toBe(1_200_000)
        ->and($line->total)->toBe(12_000_000)
        ->and($line->snapshot())->toBe([
            'roll_type_id' => $roll->id,
            'labels_per_roll' => 1000,
            'core_diameter_mm' => '40.0',
            'label_size' => null,
            'price_per_label' => 1_200,
        ]);
});

it('takes the price of the level (falling back to Retail) or the given per-label price', function () {
    $roll = RollType::factory()->size(500, '25.0')->create();
    $product = Product::factory()->perLabel(12)->withRollTypes([$roll])->create(['price_wholesale' => null]);

    expect(RollPricing::line($product, $roll, 1, null, PriceLevel::Dealer)->rollPrice)->toBe(1_100 * 500)
        ->and(RollPricing::line($product, $roll, 1, null, PriceLevel::Wholesale)->pricePerLabel)->toBe(1_200)
        ->and(RollPricing::line($product, $roll, 3, 900, PriceLevel::Retail)->total)->toBe(900 * 500 * 3);
});

it('gives no amounts when the price is on request', function () {
    $roll = RollType::factory()->create();
    $product = Product::factory()->perLabel()->withRollTypes([$roll])->create(['price' => null, 'price_dealer' => null, 'price_wholesale' => null]);

    $line = RollPricing::line($product, $roll, 4, null, PriceLevel::Retail);

    expect($line->hasPrice())->toBeFalse()
        ->and($line->rollPrice)->toBeNull()
        ->and($line->total)->toBeNull();
});

it('rejects roll types that are not attached or inactive, unit-priced products, bad rolls and tiyin prices', function () {
    $attached = RollType::factory()->create();
    $inactive = RollType::factory()->inactive()->create();
    $other = RollType::factory()->create();
    $product = Product::factory()->perLabel()->withRollTypes([$attached, $inactive])->create();
    $unitPriced = Product::factory()->consumable()->withRollTypes([RollType::factory()->create()])->create();

    expect(rollPricingErrorKeys(fn () => RollPricing::line($product, $other, 1, null, PriceLevel::Retail)))->toBe(['roll_type'])
        ->and(rollPricingErrorKeys(fn () => RollPricing::line($product, $inactive, 1, null, PriceLevel::Retail)))->toBe(['roll_type'])
        ->and(rollPricingErrorKeys(fn () => RollPricing::line($product->load('rollTypes'), $inactive, 1, null, PriceLevel::Retail)))->toBe(['roll_type'])
        ->and(rollPricingErrorKeys(fn () => RollPricing::line($unitPriced, $unitPriced->rollTypes()->first(), 1, null, PriceLevel::Retail)))->toBe(['roll_type'])
        ->and(rollPricingErrorKeys(fn () => RollPricing::line($product, $attached, 0, null, PriceLevel::Retail)))->toBe(['rolls'])
        ->and(rollPricingErrorKeys(fn () => RollPricing::line($product, $attached, 1, 1_250, PriceLevel::Retail)))->toBe(['price'])
        ->and(rollPricingErrorKeys(fn () => RollPricing::line($product, $attached, 1, PHP_INT_MAX - (PHP_INT_MAX % 100), PriceLevel::Retail)))->toBe(['price']);
});

it('snapshots the label size from the label_width and label_height attributes', function () {
    $roll = RollType::factory()->create();
    $product = Product::factory()->perLabel()->withRollTypes([$roll])->create();
    $width = Attribute::factory()->number()->create(['code' => 'label_width']);
    $height = Attribute::factory()->number()->create(['code' => 'label_height']);

    expect(RollPricing::labelSize($product))->toBeNull();

    ProductAttributeValue::factory()->for($product)->for($width)->number(100)->create();

    expect(RollPricing::labelSize($product))->toBeNull();

    ProductAttributeValue::factory()->for($product)->for($height)->number(150)->create();

    expect(RollPricing::labelSize($product))->toBe('100×150')
        ->and(RollPricing::labelSize($product->load('attributeValues.attribute')))->toBe('100×150')
        ->and(RollPricing::line($product, $roll, 2, null, PriceLevel::Retail)->labelSize)->toBe('100×150');
});

it('describes a roll line in every locale', function () {
    expect(RollPricing::describe('100×150', 500, '40.0', 10, 'en'))->toBe('100×150 mm, 500 labels/roll, core 40 mm × 10 rolls')
        ->and(RollPricing::describe(null, 1000, '12.7', 1, 'en'))->toBe('1,000 labels/roll, core 12.7 mm × 1 roll')
        ->and(RollPricing::describe('58×40', 500, '40.0', 5, 'ru'))->toBe('58×40 мм, 500 этикеток/рулон, втулка 40 мм × 5 рулонов')
        ->and(RollPricing::describe(null, 2000, '76.0', null, 'uz'))->toBe("2\u{00A0}000 yorliq/rulon, gilza 76 mm");
});
