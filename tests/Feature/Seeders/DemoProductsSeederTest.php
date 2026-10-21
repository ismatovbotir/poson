<?php

use App\Models\Product;
use App\Models\ProductRelation;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoProductsSeeder;

/**
 * @return list<array<string, mixed>>
 */
function demoProductRows(): array
{
    return require database_path('seeders/data/products.php');
}

it('seeds every demo product through SaveProduct, published and complete', function () {
    $this->seed(CatalogSeeder::class);
    $seeder = app(DemoProductsSeeder::class);
    $seeder->setContainer(app())->__invoke();

    $rows = demoProductRows();
    $skus = array_map(fn (array $row) => mb_strtoupper($row['sku']), $rows);

    expect($seeder->warnings)->toBe([])
        ->and($skus)->toHaveCount(34)
        ->and(array_unique($skus))->toHaveCount(34)
        ->and(Product::query()->count())->toBe(34)
        ->and(Product::query()->where('is_active', true)->count())->toBe(34)
        ->and(Product::query()->where('has_missing_specs', true)->count())->toBe(0)
        ->and(Product::query()->whereNull('search_text')->count())->toBe(0)
        ->and(ProductRelation::query()->count())->toBe(collect($rows)->sum(fn ($row) => count($row['related'] ?? [])));
});

it('stores the Retail price in tiyin', function () {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);

    foreach (demoProductRows() as $row) {
        $product = Product::query()->where('sku', mb_strtoupper($row['sku']))->sole();

        expect($product->price)->toBe($row['price'] === null ? null : $row['price'] * 100)
            ->and($product->price_dealer)->toBe($row['price_dealer'] === null ? null : $row['price_dealer'] * 100);
    }
});

it('is idempotent by SKU', function () {
    $this->seed([CatalogSeeder::class, DemoProductsSeeder::class]);
    $first = [Product::withTrashed()->count(), ProductRelation::query()->count()];

    $this->seed(DemoProductsSeeder::class);

    expect([Product::withTrashed()->count(), ProductRelation::query()->count()])->toBe($first);
});

it('uses no discount or low-price wording in the demo copy', function () {
    $copy = collect(demoProductRows())
        ->map(fn (array $row) => collect($row)->only(['name', 'short_description', 'description', 'meta_title', 'meta_description'])->flatten()->implode(' '))
        ->implode(' ');

    expectNoBannedWording($copy);
});
