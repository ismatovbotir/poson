<?php

use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Site\HomeCache;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan 38 migration B (2026_11_24_122212_add_roll_diagram_specs): RD-9, RD-10, RD-11.
 */
const ROLL_SPEC_CODES = ['ribbon_colour', 'paper_length', 'roll_core'];

function rollSpecsMigration(): Migration
{
    return require database_path('migrations/2026_11_24_122212_add_roll_diagram_specs.php');
}

/**
 * The catalog as production had it before plan 38: seeded, without the three new attributes.
 */
function catalogBeforeRollSpecs(): void
{
    test()->seed(CatalogSeeder::class);

    foreach (Attribute::query()->whereIn('code', ROLL_SPEC_CODES)->get() as $attribute) {
        $attribute->delete();
    }
}

function skuProduct(string $sku, string $category): Product
{
    return Product::factory()->consumable()->inCategory(Category::query()->where('slug', $category)->firstOrFail())->create(['sku' => $sku]);
}

/**
 * @return array<string, int>
 */
function rollSpecCounts(): array
{
    $ids = Attribute::query()->whereIn('code', ROLL_SPEC_CODES)->pluck('id');

    return [
        'attributes' => $ids->count(),
        'options' => AttributeOption::query()->whereIn('attribute_id', $ids)->count(),
        'pivots' => DB::table('category_attribute')->whereIn('attribute_id', $ids)->count(),
        'values' => ProductAttributeValue::query()->whereIn('attribute_id', $ids)->count(),
        'missing' => Product::query()->where('has_missing_specs', true)->count(),
    ];
}

function specValue(Product $product, string $code): ?ProductAttributeValue
{
    return ProductAttributeValue::query()->where('product_id', $product->id)
        ->where('attribute_id', Attribute::query()->where('code', $code)->value('id'))
        ->with('option')
        ->first();
}

describe('RD-9 idempotent, never overwrites', function () {
    it('adds the attributes, options with hex, pivots and the frozen values, and the same counts on a second run', function () {
        catalogBeforeRollSpecs();
        $black = skuProduct('128', 'ribbons');
        $green = skuProduct('213', 'ribbons');
        $gold = skuProduct('214', 'ribbons');
        $multi = skuProduct('165', 'ribbons');
        $coreless = skuProduct('201', 'receipt-paper');
        $cored = skuProduct('47', 'receipt-paper');
        $version = HomeCache::version();

        rollSpecsMigration()->up();
        $first = rollSpecCounts();
        $afterFirst = HomeCache::version();
        rollSpecsMigration()->up();

        expect(rollSpecCounts())->toBe($first)
            ->and($first)->toMatchArray(['attributes' => 3, 'options' => 10, 'pivots' => 3, 'values' => 7])
            ->and($afterFirst)->not->toBe($version)
            ->and(HomeCache::version())->toBe($afterFirst)
            ->and(specValue($black, 'ribbon_colour')->option->getTranslation('value', 'en'))->toBe('Black')
            ->and(specValue($black, 'ribbon_colour')->option->hexColor())->toBe('#111111')
            ->and(specValue($green, 'ribbon_colour')->option->hexColor())->toBe('#00843D')
            ->and(specValue($gold, 'ribbon_colour')->option->getTranslation('value', 'uz'))->toBe('Oltin rang')
            ->and(specValue($multi, 'ribbon_colour'))->toBeNull()
            ->and((float) specValue($coreless, 'paper_length')->value_number)->toBe(12.0)
            ->and(specValue($coreless, 'roll_core')->option->getTranslation('value', 'en'))->toBe('Coreless')
            ->and(specValue($cored, 'roll_core')->option->getTranslation('value', 'en'))->toBe('With core')
            ->and(DB::table('category_attribute')->where('attribute_id', Attribute::query()->where('code', 'paper_length')->value('id'))->value('is_required'))->toBeTruthy();
    });

    it('reuses an existing attribute and option ("qora") with its meta and the admin hex', function () {
        catalogBeforeRollSpecs();
        $attribute = Attribute::factory()->select(['qora '])->create(['code' => 'ribbon_colour', 'name' => ['uz' => 'Rang', 'ru' => 'Цвет', 'en' => 'Colour'], 'sort' => 7]);
        $attribute->options()->first()->forceFill(['hex_color' => '#222222'])->save();
        $white = AttributeOption::factory()->for($attribute)->create(['value' => ['uz' => 'Oq', 'ru' => 'Oq', 'en' => 'Oq'], 'hex_color' => null]);
        $product = skuProduct('128', 'ribbons');

        rollSpecsMigration()->up();

        expect(Attribute::query()->where('code', 'ribbon_colour')->count())->toBe(1)
            ->and($attribute->fresh()->getTranslation('name', 'uz'))->toBe('Rang')
            ->and($attribute->fresh()->sort)->toBe(7)
            ->and($attribute->options()->count())->toBe(8)
            ->and($attribute->options()->get()->filter(fn (AttributeOption $option): bool => mb_strtolower(trim($option->getTranslation('value', 'uz'))) === 'qora'))->toHaveCount(1)
            ->and(specValue($product, 'ribbon_colour')->option->hexColor())->toBe('#222222')
            ->and($white->fresh()->hexColor())->toBe('#FFFFFF');
    });

    it('keeps existing values, skips, wrong-category SKUs and a type mismatch untouched', function () {
        catalogBeforeRollSpecs();
        $paperLength = Attribute::factory()->number('m')->create(['code' => 'paper_length']);
        $edited = skuProduct('184', 'receipt-paper');
        ProductAttributeValue::factory()->for($edited)->number(31)->create(['attribute_id' => $paperLength->id]);
        $skipped = skuProduct('186', 'receipt-paper');
        DB::table('product_attribute_skips')->insert(['product_id' => $skipped->id, 'attribute_id' => $paperLength->id]);
        $moved = skuProduct('128', 'receipt-paper');
        Attribute::factory()->create(['code' => 'roll_core']);
        $cored = skuProduct('47', 'receipt-paper');

        rollSpecsMigration()->up();

        expect((float) specValue($edited, 'paper_length')->value_number)->toBe(31.0)
            ->and(specValue($skipped, 'paper_length'))->toBeNull()
            ->and(specValue($moved, 'ribbon_colour'))->toBeNull()
            ->and(specValue($cored, 'roll_core'))->toBeNull()
            ->and(Attribute::query()->where('code', 'roll_core')->first()->options()->count())->toBe(0)
            ->and((float) specValue($cored, 'paper_length')->value_number)->toBe(80.0);
    });

    it('only ever sets has_missing_specs to true', function () {
        catalogBeforeRollSpecs();
        $unknown = skuProduct('9999', 'receipt-paper');
        $listed = skuProduct('57', 'receipt-paper');
        $flagged = skuProduct('246', 'receipt-paper');
        $flagged->forceFill(['has_missing_specs' => true])->saveQuietly();

        rollSpecsMigration()->up();

        expect($unknown->fresh()->has_missing_specs)->toBeTrue()
            ->and($listed->fresh()->has_missing_specs)->toBeFalse()
            ->and($flagged->fresh()->has_missing_specs)->toBeTrue();
    });

    it('does nothing on an empty database', function () {
        $version = HomeCache::version();

        rollSpecsMigration()->up();

        expect(Attribute::query()->count())->toBe(0)
            ->and(AttributeOption::query()->count())->toBe(0)
            ->and(HomeCache::version())->toBe($version);
    });
});

describe('RD-10 frozen literals equal the seed data', function () {
    it('uses the uz literals of data/attributes.php (with ʻ U+02BB in Koʻk) and the product seed specs', function () {
        $constants = (new ReflectionClass(rollSpecsMigration()))->getReflectionConstants();
        $attributes = collect($constants)->firstWhere('name', 'ATTRIBUTES')->getValue();
        $values = collect($constants)->firstWhere('name', 'VALUES')->getValue();
        $data = collect(require database_path('seeders/data/attributes.php'))->keyBy('code');

        foreach ($attributes as $code => $definition) {
            $row = $data->get($code);

            expect($row)->not->toBeNull()
                ->and($row['name'])->toBe($definition['name'])
                ->and($row['type'])->toBe($definition['type'])
                ->and($row['unit'])->toBe($definition['unit'])
                ->and($row['options'])->toBe($definition['options'])
                ->and($row['categories'][$definition['category']]['is_required'])->toBe($definition['is_required']);
        }

        expect(collect($attributes['ribbon_colour']['options'])->pluck('uz')->all())->toContain("Ko\u{02BB}k");

        $rows = collect(require database_path('seeders/data/products/consumables.php'))->keyBy('sku');

        foreach ($values as $code => $skus) {
            foreach ($skus as $sku => $value) {
                $spec = $rows->get((string) $sku)['specs'][$code] ?? null;
                $spec = is_array($spec) ? $spec['value'] : $spec;

                expect((string) $spec)->toBe((string) $value, "{$code} {$sku}");
            }
        }

        $ribbons = $rows->filter(fn (array $row): bool => $row['category'] === 'ribbons');
        expect($ribbons)->toHaveCount(34)
            ->and($ribbons->filter(fn (array $row): bool => ($row['specs']['ribbon_colour'] ?? null) === 'Qora'))->toHaveCount(28)
            ->and($ribbons->filter(fn (array $row): bool => ! isset($row['specs']['ribbon_colour']))->keys()->map(fn (int|string $sku): string => (string) $sku)->sort()->values()->all())->toBe(['165', '166', '168', '251']);
    });

    it('lets a later CatalogSeeder run add no duplicates', function () {
        catalogBeforeRollSpecs();
        skuProduct('213', 'ribbons');
        rollSpecsMigration()->up();
        $counts = rollSpecCounts();

        test()->seed(CatalogSeeder::class);

        expect(rollSpecCounts())->toBe($counts);
    });

    it('writes a valid seed hex on create and drops an invalid one', function () {
        test()->seed(CatalogSeeder::class);
        $options = Attribute::query()->where('code', 'ribbon_colour')->firstOrFail()->options()->get();

        expect($options)->toHaveCount(8)
            ->and($options->map(fn (AttributeOption $option): ?string => $option->hexColor())->filter()->count())->toBe(8);

        $attribute = Attribute::factory()->select([])->create();
        $seedOptions = new ReflectionMethod(CatalogSeeder::class, 'seedOptions');
        $seedOptions->invoke(new CatalogSeeder, $attribute, [
            ['uz' => 'A', 'ru' => 'A', 'en' => 'A', 'hex' => '#00843d'],
            ['uz' => 'B', 'ru' => 'B', 'en' => 'B', 'hex' => "#00843D\n"],
            ['uz' => 'C', 'ru' => 'C', 'en' => 'C', 'hex' => 'red'],
        ]);

        $stored = $attribute->options()->orderBy('sort')->get();
        expect($stored->pluck('hex_color')->all())->toBe(['#00843D', null, null])
            ->and($stored->first()->getTranslations('value'))->toBe(['uz' => 'A', 'ru' => 'A', 'en' => 'A']);
    });
});

describe('RD-11 down() keeps admin data', function () {
    it('removes what up() created', function () {
        catalogBeforeRollSpecs();
        skuProduct('213', 'ribbons');
        skuProduct('201', 'receipt-paper');
        $migration = rollSpecsMigration();
        $migration->up();

        $migration->down();

        expect(Attribute::query()->whereIn('code', ROLL_SPEC_CODES)->count())->toBe(0);
    });

    it('keeps an attribute and its values when a value exists on a non-listed SKU, without an FK error', function () {
        catalogBeforeRollSpecs();
        skuProduct('213', 'ribbons');
        $other = skuProduct('5555', 'ribbons');
        $migration = rollSpecsMigration();
        $migration->up();
        ProductAttributeValue::factory()->for($other)->option(Attribute::query()->where('code', 'ribbon_colour')->firstOrFail()->options()->first())->create();

        $migration->down();

        expect(Attribute::query()->where('code', 'ribbon_colour')->exists())->toBeTrue()
            ->and(specValue($other, 'ribbon_colour'))->not->toBeNull()
            ->and(Attribute::query()->where('code', 'paper_length')->exists())->toBeFalse();
    });

    it('keeps a pre-existing or renamed attribute and an admin-added option', function () {
        catalogBeforeRollSpecs();
        Attribute::factory()->number('m')->create(['code' => 'paper_length', 'name' => ['uz' => 'Uzunlik', 'ru' => 'Длина', 'en' => 'Length']]);
        $migration = rollSpecsMigration();
        $migration->up();
        AttributeOption::factory()->for(Attribute::query()->where('code', 'roll_core')->firstOrFail())->create();

        $migration->down();

        expect(Attribute::query()->where('code', 'paper_length')->exists())->toBeTrue()
            ->and(Attribute::query()->where('code', 'roll_core')->exists())->toBeTrue()
            ->and(Attribute::query()->where('code', 'ribbon_colour')->exists())->toBeFalse();
    });
});
