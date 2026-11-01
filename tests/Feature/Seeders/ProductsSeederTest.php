<?php

use App\Models\ApiToken;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductRelation;
use App\Models\Setting;
use App\Support\DemoContent;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductsSeeder;
use Illuminate\Support\Facades\Log;
use Tests\Support\DemoProductsFixtureSeeder;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
});

/**
 * A data row (the shape of the real product data files).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function seederRow(string $sku, array $overrides = []): array
{
    return [
        'sku' => $sku,
        'model' => 'M-'.$sku,
        'brand' => 'ZEBRA',
        'category' => 'receipt-printers',
        'type' => 'hardware',
        'unit' => 'piece',
        'min_qty' => 1,
        'sale_mode' => 'both',
        'stock_status' => 'on_order',
        'warranty_months' => 12,
        'badge' => 'hit',
        'is_featured' => true,
        'price' => 1_450_000,
        'name' => ['uz' => 'Printer '.$sku, 'ru' => 'Принтер '.$sku, 'en' => 'Printer '.$sku],
        'short_description' => ['uz' => 'Qisqa', 'ru' => 'Кратко', 'en' => 'Short'],
        'description' => ['uz' => '<p>Tavsif</p>', 'ru' => '<p>Описание</p>', 'en' => '<p>Description</p>'],
        'meta_title' => ['uz' => 'Sarlavha', 'ru' => 'Заголовок', 'en' => 'Title'],
        'meta_description' => ['uz' => 'Meta', 'ru' => 'Мета', 'en' => 'Meta'],
        'specs' => ['auto_cutter' => true],
        'related' => [],
        'has_missing_specs' => false,
        'suspicious_price' => false,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function runProductsSeeder(array $rows): ProductsSeeder
{
    $seeder = app(ProductsSeeder::class);
    $seeder->rows = $rows;
    $seeder->setContainer(app())->__invoke();

    return $seeder;
}

it('creates drafts with the Retail price only, no internal data and no other price levels (S4)', function () {
    $seeder = runProductsSeeder([
        seederRow(' a-1 '),
        seederRow('A-2', ['suspicious_price' => true]),
        seederRow('A-3', ['price' => null]),
        seederRow('A-4', ['price' => 0]),
    ]);

    $one = Product::query()->where('sku', 'A-1')->sole();

    expect($seeder->stats['created'])->toBe(4)
        ->and($seeder->stats['suspicious_price'])->toBe(1)
        ->and($one->is_active)->toBeFalse()
        ->and($one->is_featured)->toBeFalse()
        ->and($one->badge)->toBeNull()
        ->and($one->price)->toBe(145_000_000)
        ->and($one->price_dealer)->toBeNull()
        ->and($one->price_wholesale)->toBeNull()
        ->and($one->price_synced_at)->toBeNull()
        ->and($one->getMedia('images'))->toHaveCount(0)
        ->and($one->brand->name)->toBe('Zebra')
        ->and(Product::query()->where('sku', 'A-2')->value('price'))->toBeNull()
        ->and(Product::query()->where('sku', 'A-3')->value('price'))->toBeNull()
        ->and(Product::query()->where('sku', 'A-4')->value('price'))->toBeNull()
        ->and(Product::query()->where('is_active', true)->count())->toBe(0);
});

it('stores a per-label price as whole soʻm per one label in tiyin and attaches the roll types', function () {
    runProductsSeeder([seederRow('LBL-1', [
        'category' => 'thermal-labels', 'type' => 'consumable', 'unit' => 'roll', 'brand' => null, 'specs' => [],
        'pricing_unit' => 'label', 'price' => 32, 'roll_types' => [['core' => '40', 'labels' => 1000]],
    ])]);

    $product = Product::query()->where('sku', 'LBL-1')->sole();

    expect($product->pricing_unit->value)->toBe('label')
        ->and($product->price)->toBe(3200)
        ->and($product->rollTypes()->count())->toBe(1);
});

it('leaves an existing, edited, trashed or price-synced product untouched and creates no duplicate (S2)', function () {
    runProductsSeeder([seederRow('E-1'), seederRow('E-2'), seederRow('E-3')]);

    $edited = Product::query()->where('sku', 'E-1')->sole();
    $edited->forceFill(['is_active' => true, 'price' => 999_900, 'is_featured' => true])->save();
    $edited->setTranslation('name', 'uz', 'Edited by admin')->save();
    Product::query()->where('sku', 'E-2')->sole()->delete();
    Product::query()->where('sku', 'E-3')->update(['price_synced_at' => now(), 'price' => 5_000_00]);

    $before = Product::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();
    Setting::query()->where('key', ProductsSeeder::RECORD_KEY)->delete();

    $seeder = runProductsSeeder([seederRow('e-1'), seederRow('E-2'), seederRow('E-3')]);

    expect($seeder->stats)->toMatchArray(['created' => 0, 'skipped_existing' => 2, 'skipped_trashed' => 1])
        ->and(Product::withTrashed()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(Product::query()->where('sku', 'E-2')->exists())->toBeFalse();
});

it('is idempotent: a second run creates nothing', function () {
    runProductsSeeder([seederRow('I-1'), seederRow('I-2')]);
    $seeder = runProductsSeeder([seederRow('I-1'), seederRow('I-2')]);

    expect(Product::withTrashed()->count())->toBe(2)
        ->and($seeder->stats['created'])->toBe(0);
});

it('records created SKUs and never recreates a force-deleted product (S3)', function () {
    runProductsSeeder([seederRow('R-1')]);

    expect(Setting::query()->where('key', ProductsSeeder::RECORD_KEY)->value('value'))->toBe(['skus' => ['R-1']]);

    Product::query()->where('sku', 'R-1')->sole()->forceDelete();
    $seeder = runProductsSeeder([seederRow('R-1')]);

    expect(Product::withTrashed()->count())->toBe(0)
        ->and($seeder->stats['skipped_recorded'])->toBe(1);
});

it('links related products only when both exist (S2, plan 16)', function () {
    runProductsSeeder([
        seederRow('L-1', ['related' => [['sku' => 'L-2', 'type' => 'accessory'], ['sku' => 'GHOST', 'type' => 'accessory']]]),
        seederRow('L-2'),
    ]);

    expect(ProductRelation::query()->count())->toBe(1);
});

it('rejects forbidden keys, other price levels and unsafe copy before writing anything (S5, S10)', function (array $override) {
    expect(fn () => runProductsSeeder([seederRow('OK-1'), seederRow('BAD-1', $override)]))
        ->toThrow(RuntimeException::class, 'BAD-1');

    expect(Product::withTrashed()->count())->toBe(0);
})->with([
    'cost key' => [['cost_som' => 100]],
    'source url' => [['source_url' => 'https://example.test/x']],
    'purchase key' => [['purchase_price' => 100]],
    'dealer price' => [['price_dealer' => 100]],
    'wholesale price' => [['price_wholesale' => 100]],
    'banned wording' => [['short_description' => ['uz' => 'Eng arzon narx', 'ru' => 'x', 'en' => 'y']]],
]);

it('keeps tiyin precision for unit prices and saves a fractional per-label price without a price', function () {
    $seeder = runProductsSeeder([
        seederRow('F-1', ['price' => 199999.5]),
        seederRow('F-2', ['category' => 'thermal-labels', 'type' => 'consumable', 'unit' => 'roll', 'brand' => null, 'specs' => [], 'pricing_unit' => 'label', 'price' => 12.02, 'suspicious_price' => false]),
    ]);

    expect(Product::query()->where('sku', 'F-1')->value('price'))->toBe(19_999_950)
        ->and(Product::query()->where('sku', 'F-2')->value('price'))->toBeNull()
        ->and($seeder->stats['fractional_price'])->toBe(1);
});

it('logs counts only (S14)', function () {
    Log::spy();

    runProductsSeeder([seederRow('C-1', ['name' => ['uz' => 'Secret Name', 'ru' => 'Secret Name', 'en' => 'Secret Name']])]);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_starts_with($message, 'ProductsSeeder: rows=1 created=1')
        && ! str_contains($message, 'Secret') && ! str_contains($message, '145'))->once();
});

it('does not run from DatabaseSeeder in production and deletes nothing (S13)', function () {
    $keep = Product::factory()->create(['sku' => 'KEEP-1']);
    app()['env'] = 'production';

    expect(fn () => app(DatabaseSeeder::class)->setContainer(app())->__invoke())->toThrow(RuntimeException::class, 'LaunchSeeder');

    expect($keep->fresh())->not->toBeNull()
        ->and(Product::withTrashed()->count())->toBe(1);
});

it('keeps the prices of a price-synced seeded product when the seeder runs again (S15)', function () {
    runProductsSeeder([seederRow('P-1')]);
    $plain = fakeApiTokenValue();
    ApiToken::factory()->withPlainText($plain)->create();

    postPrices(['items' => [priceItem('P-1', 2_000_000, 1_900_000, 1_800_000)]], $plain)->assertOk();
    $synced = Product::query()->where('sku', 'P-1')->sole();

    expect($synced->price)->toBe(200_000_000)
        ->and($synced->price_dealer)->toBe(190_000_000)
        ->and($synced->price_wholesale)->toBe(180_000_000)
        ->and($synced->price_synced_at)->not->toBeNull();

    runProductsSeeder([seederRow('P-1')]);
    $after = Product::query()->where('sku', 'P-1')->sole();

    expect($after->price)->toBe(200_000_000)
        ->and($after->price_dealer)->toBe(190_000_000)
        ->and($after->price_synced_at->equalTo($synced->price_synced_at))->toBeTrue();
});

it('never lets demo:remove target a real SKU: the legacy SKUs are a frozen list (plan 16)', function () {
    expect(DemoContent::productSkus())->toBe(DemoContent::LEGACY_PRODUCT_SKUS)
        ->and(DemoContent::productSkus())->toHaveCount(39)
        ->and(array_unique(DemoContent::productSkus()))->toHaveCount(39);

    // The fixture products are exactly the legacy demo set.
    app(DemoProductsFixtureSeeder::class)->setContainer(app())->__invoke();

    expect(Product::query()->pluck('sku')->sort()->values()->all())->toBe(collect(DemoContent::LEGACY_PRODUCT_SKUS)->sort()->values()->all());

    // A dry run on a database holding real seeded products lists none of them.
    Product::query()->delete();
    runProductsSeeder([seederRow('REAL-1')]);
    DemoContent::remember('product_ids', Product::query()->pluck('id')->all());
    app()['env'] = 'local';

    $this->artisan('demo:remove', ['--dry-run' => true])->assertSuccessful();

    expect(Product::query()->where('sku', 'REAL-1')->exists())->toBeTrue();
});

it('creates the new categories inactive and leaves existing categories as an admin left them (S1, S16)', function () {
    foreach (['textile-tapes', 'accessories-parts', 'accessories', 'spare-parts', 'price-checkers'] as $slug) {
        expect(Category::query()->where('slug', $slug)->value('is_active'))->toBeFalse($slug);
    }

    expect(Category::query()->where('slug', 'textile-tapes')->sole()->parent->slug)->toBe('labels-ribbons')
        ->and(Category::query()->where('slug', 'price-checkers')->sole()->parent->slug)->toBe('pos')
        ->and(Category::query()->where('slug', 'accessories')->sole()->parent->slug)->toBe('accessories-parts')
        ->and(Category::query()->where('slug', 'accessories-parts')->value('parent_id'))->toBeNull();
});

it('seeds every real data row as a draft with no spec or validation problems', function () {
    $rows = ProductsSeeder::loadRows();
    $seeder = app(ProductsSeeder::class);
    $seeder->setContainer(app())->__invoke();

    expect($seeder->warnings)->toBe([])
        ->and($seeder->stats['invalid'])->toBe(0)
        ->and($seeder->stats['created'])->toBe(count($rows))
        ->and(Product::query()->count())->toBe(count($rows))
        ->and(Product::query()->where('is_active', true)->count())->toBe(0)
        ->and(Product::query()->whereNull('brand_id')->whereNotNull('model')->count())->toBeLessThanOrEqual(count($rows));
});
