<?php

namespace Tests\Support;

use App\Actions\Catalog\AddProductRelation;
use App\Actions\Catalog\SaveProduct;
use App\Enums\ProductBadge;
use App\Enums\ProductPricingUnit;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\RelationType;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductRelation;
use App\Models\RollType;
use App\Support\Catalog\CategoryTree;
use Database\Seeders\Concerns\ResolvesProductSeedData;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

/**
 * TEST-ONLY fixture seeder (plan 16): the former demo products (39 rows in
 * tests/Fixtures/demo-products.php, published, with dealer / wholesale prices
 * and software / service rows) so site and cart tests have a rich active
 * catalog. Never part of any app seeder. Needs CatalogSeeder first. Rows go
 * through SaveProduct; a row with incomplete required specs is saved as a
 * draft. Idempotent by SKU (updates). Same row shape as ProductsSeeder data.
 */
class DemoProductsFixtureSeeder extends Seeder
{
    use ResolvesProductSeedData;

    public function run(SaveProduct $saveProduct, AddProductRelation $addRelation): void
    {
        CategoryTree::flush();

        $rows = require base_path('tests/Fixtures/demo-products.php');
        $categories = Category::query()->pluck('id', 'slug');
        $brands = Brand::query()->pluck('id', 'name');
        $attributes = Attribute::query()->with('options')->get()->keyBy('code');
        $rollTypes = RollType::query()->get()->keyBy(fn (RollType $rollType): string => $rollType->labels_per_roll.'@'.$rollType->core_diameter_mm);

        $seededIds = [];

        foreach ($rows as $row) {
            $categoryId = $categories[$row['category']] ?? null;

            if ($categoryId === null) {
                $this->warn($row['sku'], "unknown category {$row['category']}, product skipped");

                continue;
            }

            $product = Product::withTrashed()->where('sku', mb_strtoupper($row['sku']))->first();
            $product?->restore();

            $attributesForSave = [
                'type' => ProductType::from($row['type']),
                'category_id' => (int) $categoryId,
                'brand_id' => $row['brand'] !== null ? ($brands[$row['brand']] ?? null) : null,
                'sku' => $row['sku'],
                'model' => $row['model'] ?? null,
                'name' => $row['name'],
                'short_description' => $row['short_description'] ?? [],
                'description' => $row['description'] ?? [],
                'meta_title' => $row['meta_title'] ?? [],
                'meta_description' => $row['meta_description'] ?? [],
                'price' => $this->tiyin($row['price'] ?? null),
                'price_dealer' => $this->tiyin($row['price_dealer'] ?? null),
                'price_wholesale' => $this->tiyin($row['price_wholesale'] ?? null),
                'pricing_unit' => ProductPricingUnit::from($row['pricing_unit'] ?? 'unit'),
                'roll_type_ids' => $this->rollTypeIds($row['sku'], $row['roll_types'] ?? [], $rollTypes),
                'unit' => ProductUnit::from($row['unit']),
                'min_qty' => (int) $row['min_qty'],
                'sale_mode' => SaleMode::from($row['sale_mode']),
                'stock_status' => StockStatus::from($row['stock_status']),
                'warranty_months' => $row['warranty_months'] ?? null,
                'badge' => isset($row['badge']) ? ProductBadge::from($row['badge']) : null,
                'is_active' => true,
                'is_featured' => (bool) ($row['is_featured'] ?? false),
            ];
            $specs = $this->specs($row['sku'], $row['specs'] ?? [], $attributes);

            try {
                $seededIds[] = $saveProduct->handle($product, $attributesForSave, $specs)->id;
            } catch (ValidationException $exception) {
                if (! array_key_exists('specs', $exception->errors())) {
                    throw $exception;
                }

                $this->warn($row['sku'], 'required specs incomplete, seeded as a draft');
                $seededIds[] = $saveProduct->handle($product, [...$attributesForSave, 'is_active' => false], $specs)->id;
            }
        }

        $this->linkRelations($rows, $addRelation);

    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function linkRelations(array $rows, AddProductRelation $addRelation): void
    {
        $products = Product::query()->whereIn('sku', array_map(fn (array $row): string => mb_strtoupper($row['sku']), $rows))->get()->keyBy('sku');

        foreach ($rows as $row) {
            $product = $products->get(mb_strtoupper($row['sku']));

            foreach ($row['related'] ?? [] as $link) {
                $related = $products->get(mb_strtoupper($link['sku']));
                $type = RelationType::from($link['type']);

                if ($product === null || $related === null) {
                    $this->warn($row['sku'], "related SKU {$link['sku']} not found");

                    continue;
                }

                $exists = ProductRelation::query()
                    ->where('product_id', $product->id)
                    ->where('related_id', $related->id)
                    ->where('type', $type)
                    ->exists();

                if (! $exists) {
                    $addRelation->handle($product, $related, $type);
                }
            }
        }
    }

    private function tiyin(?int $sums): ?int
    {
        return $sums === null ? null : $sums * 100;
    }
}
