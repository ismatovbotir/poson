<?php

namespace Database\Seeders;

use App\Actions\Catalog\AddProductRelation;
use App\Actions\Catalog\SaveProduct;
use App\Enums\AttributeType;
use App\Enums\ProductBadge;
use App\Enums\ProductPricingUnit;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\RelationType;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductRelation;
use App\Models\RollType;
use App\Support\Catalog\CategoryTree;
use App\Support\DemoContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Demo products for local / staging (plan 03 T15) from data/products.php.
 * Outside local / staging only through LaunchSeeder (DemoSeeding, plan 15):
 * in production only when no product exists yet (trashed ones included),
 * and every product is quote-only there. Needs CatalogSeeder first
 * (categories, attributes with options, brands). The SKUs identify the demo
 * products for `php artisan demo:remove` (App\Support\DemoContent).
 *
 * Every product goes through SaveProduct, published; a product whose
 * required specs are incomplete in the data (e.g. a licence type confirmed
 * only in the quote) is saved as a draft instead, with a warning, rather
 * than inventing a value. Specs map attribute codes to values: select and
 * multiselect values are matched to options by their uz value; unknown codes
 * or values are logged and skipped, never fatal. At most
 * Product::MAX_KEY_SPECS key specs are kept. Relations are linked once all
 * products exist. Idempotent by SKU (an existing product is updated). No images.
 *
 * Label products priced per label (plan 08): 'pricing_unit' => 'label' with
 * prices per one label in soʻm, and 'roll_types' as labels / core pairs
 * matched to the roll types CatalogSeeder seeds (unknown pairs are warned
 * about and skipped).
 */
class DemoProductsSeeder extends Seeder
{
    /**
     * Unmapped spec problems of the last run ("SKU: message").
     *
     * @var list<string>
     */
    public array $warnings = [];

    public function run(SaveProduct $saveProduct, AddProductRelation $addRelation): void
    {
        // Plan 15 A2/A3: local / staging, or LaunchSeeder's opt-in; in production
        // only into an empty catalog (an update by SKU would overwrite edited or
        // restore deleted products).
        if (! DemoSeeding::allowed() || (app()->isProduction() && (DemoContent::wasRemoved() || Product::withTrashed()->exists()))) {
            return;
        }

        // Plan 15 A5: in production demo products are quote-only, never sold through the cart.
        $quoteOnly = app()->isProduction();

        CategoryTree::flush();

        $rows = require database_path('seeders/data/products.php');
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
                'sale_mode' => $quoteOnly ? SaleMode::Quote : SaleMode::from($row['sale_mode']),
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

        DemoContent::remember('product_ids', $seededIds);

        foreach ($this->warnings as $warning) {
            Log::warning("DemoProductsSeeder: {$warning}");
        }
    }

    /**
     * @param  array<string, mixed>  $specs  code => value or ['value' => ..., 'key' => bool]
     * @param  Collection<string, Attribute>  $attributes
     * @return list<array<string, mixed>>
     */
    private function specs(string $sku, array $specs, Collection $attributes): array
    {
        $rows = [];
        $keys = 0;

        foreach ($specs as $code => $spec) {
            $attribute = $attributes->get($code);
            $value = is_array($spec) && array_key_exists('value', $spec) ? $spec['value'] : $spec;
            $isKey = is_array($spec) && ($spec['key'] ?? false);

            if ($attribute === null) {
                $this->warn($sku, "unknown attribute {$code}");

                continue;
            }

            if ($isKey && $keys >= Product::MAX_KEY_SPECS) {
                $this->warn($sku, 'more than '.Product::MAX_KEY_SPECS." key specs, {$code} is not a key spec");
                $isKey = false;
            }

            $row = ['attribute_id' => $attribute->id, 'skipped' => false, 'is_key' => $isKey];

            switch ($attribute->type) {
                case AttributeType::Text:
                    $row['text'] = is_array($value) ? $value : ['uz' => (string) $value];
                    break;
                case AttributeType::Number:
                    $row['number'] = (string) $value;
                    break;
                case AttributeType::Boolean:
                    $row['bool'] = (bool) $value;
                    break;
                case AttributeType::Select:
                    $row['option'] = $this->optionId($sku, $attribute, (string) $value);

                    if ($row['option'] === null) {
                        continue 2;
                    }
                    break;
                case AttributeType::Multiselect:
                    $row['options'] = array_values(array_filter(array_map(
                        fn ($item): ?int => $this->optionId($sku, $attribute, (string) $item),
                        (array) $value,
                    )));
                    break;
            }

            $keys += $isKey ? 1 : 0;
            $rows[] = $row;
        }

        return $rows;
    }

    private function optionId(string $sku, Attribute $attribute, string $value): ?int
    {
        $needle = mb_strtolower(trim($value));
        $option = $attribute->options->first(
            fn (AttributeOption $option): bool => mb_strtolower(trim((string) $option->getTranslation('value', 'uz', false))) === $needle,
        );

        if ($option === null) {
            $this->warn($sku, "no option “{$value}” for {$attribute->code}");
        }

        return $option?->id;
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

    /**
     * @param  list<array{core: string, labels: int}>  $pairs
     * @param  Collection<string, RollType>  $rollTypes  keyed "labels@core" ("1000@40.0")
     * @return list<int>
     */
    private function rollTypeIds(string $sku, array $pairs, Collection $rollTypes): array
    {
        $ids = [];

        foreach ($pairs as $pair) {
            $rollType = $rollTypes->get($pair['labels'].'@'.number_format((float) $pair['core'], 1, '.', ''));

            if ($rollType === null) {
                $this->warn($sku, "no roll type {$pair['labels']} labels / {$pair['core']} mm");

                continue;
            }

            $ids[] = $rollType->id;
        }

        return $ids;
    }

    private function tiyin(?int $sums): ?int
    {
        return $sums === null ? null : $sums * 100;
    }

    private function warn(string $sku, string $message): void
    {
        $this->warnings[] = "{$sku}: {$message}";
    }
}
