<?php

namespace Database\Seeders;

use App\Actions\Catalog\AddProductRelation;
use App\Actions\Catalog\SaveProduct;
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
use App\Models\Setting;
use App\Rules\BrandSafeText;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Database\Seeders\Concerns\ResolvesProductSeedData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The real product catalog (plan 16, imported from 1C) from
 * database/seeders/data/products/*.php (printers, scanners, pos, consumables;
 * whichever files exist). Row shape: see tests/Fixtures/demo-products.php
 * plus the keys in ROW_KEYS. Needs CatalogSeeder first (categories,
 * attributes with options, brands, roll types). Runs from LaunchSeeder (and
 * DatabaseSeeder on local / staging); production needs `db:seed --force`.
 *
 * Insert-only, never destructive (security S2-S4, S13):
 * - matched by SKU (upper-cased, trimmed, trashed products included): any
 *   existing product, trashed or not, is skipped untouched (no update, no
 *   restore, no price / activation / translation change);
 * - every created SKU is recorded in the system setting RECORD_KEY, and a
 *   recorded SKU is never created again (a force-deleted product stays gone);
 * - new products are drafts (is_active = false) with Retail price only
 *   (soʻm in the data, up to 2 decimals, stored as tiyin; dealer / wholesale are never
 *   stored; a suspicious_price row or a missing price is saved without a
 *   price, never 0; a per-label price that is not whole soʻm is the only one
 *   dropped (safety net, never rounded here), unit prices keep their 2 decimals),
 *   no images, never featured, no badge;
 * - priced per label ('pricing_unit' => 'label'): the data carries the price
 *   of ONE label in whole soʻm; it is stored the same way as everywhere (tiyin,
 *   divisible by 100, per label) and the roll types turn it into a per-roll price;
 * - internal keys (suspicious_price, has_missing_specs) are never
 *   stored; specs go through the shared resolver (existing attributes and
 *   options only), unknown ones just leave the product with missing specs;
 * - related links only between products that exist.
 *
 * The whole data set is validated before anything is written (key
 * whitelist, brand rule on every text); a failing row throws with its SKU.
 * One SaveProduct call (own transaction) per row, no delete / truncate, so
 * a re-run is safe. Output and logs are counts only (plus the SKU of a row
 * that failed validation); never prices, texts, URLs.
 */
class ProductsSeeder extends Seeder
{
    use ResolvesProductSeedData;

    public const RECORD_KEY = 'launch_products_seeded';

    /**
     * Keys a data row may carry. Anything else (cost, purchase, dealer / wholesale
     * prices ...) stops the seeder.
     *
     * @var list<string>
     */
    public const ROW_KEYS = [
        'sku', 'model', 'brand', 'category', 'type', 'unit', 'min_qty', 'sale_mode', 'stock_status',
        'warranty_months', 'badge', 'is_featured', 'price', 'pricing_unit', 'roll_types',
        'name', 'short_description', 'description', 'meta_title', 'meta_description', 'specs', 'related',
        'has_missing_specs', 'suspicious_price',
    ];

    /**
     * Keys that must be absent or null (prices of other levels are never imported).
     *
     * @var list<string>
     */
    public const NULL_ONLY_KEYS = ['price_dealer', 'price_wholesale'];

    /**
     * Translatable text fields of a row.
     *
     * @var list<string>
     */
    public const TEXT_FIELDS = ['name', 'short_description', 'description', 'meta_title', 'meta_description'];

    /**
     * Rows to seed instead of the data files (tests).
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $rows = null;

    /**
     * Counts of the last run.
     *
     * @var array<string, int>
     */
    public array $stats = [];

    /**
     * The data files that exist, in a stable order.
     *
     * @return list<string>
     */
    public static function dataFiles(): array
    {
        $files = glob(database_path('seeders/data/products/*.php')) ?: [];
        sort($files);

        return array_values($files);
    }

    /**
     * A data file's rows, loaded in an isolated scope (the files use local helper variables).
     *
     * @return list<array<string, mixed>>
     */
    public static function requireFile(string $file): array
    {
        return (array) (static fn (): mixed => require $file)();
    }

    /**
     * Rows of every existing data file.
     *
     * @return list<array<string, mixed>>
     */
    public static function loadRows(): array
    {
        $rows = [];

        foreach (self::dataFiles() as $file) {
            foreach (self::requireFile($file) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Structure and brand-rule check of one row (the data test uses it too).
     *
     * @param  array<string, mixed>  $row
     * @return list<string> problems, without any row text
     */
    public static function problems(array $row): array
    {
        $problems = [];

        foreach (array_diff(array_keys($row), self::ROW_KEYS, self::NULL_ONLY_KEYS) as $key) {
            $problems[] = "key {$key} is not allowed";
        }

        foreach (self::NULL_ONLY_KEYS as $key) {
            if (($row[$key] ?? null) !== null) {
                $problems[] = "{$key} must be empty";
            }
        }

        foreach (self::TEXT_FIELDS as $field) {
            foreach ((array) ($row[$field] ?? []) as $locale => $text) {
                if (is_string($text) && BrandSafeText::violates(strip_tags($text))) {
                    $problems[] = "{$field}.{$locale} breaks the brand rule";
                }
            }
        }

        $price = $row['price'] ?? null;

        if ($price !== null && ! self::isValidPrice($price)) {
            $problems[] = 'price must be soʻm with at most 2 decimals';
        }

        return $problems;
    }

    /**
     * Soʻm with at most 2 decimals (tiyin), not negative.
     */
    public static function isValidPrice(mixed $price): bool
    {
        return (is_int($price) || is_float($price)) && $price >= 0 && abs($price * 100 - round($price * 100)) < 1e-6;
    }

    public static function isWholeSom(mixed $price): bool
    {
        return self::isValidPrice($price) && abs($price - round($price)) < 1e-9;
    }

    public function run(SaveProduct $saveProduct, AddProductRelation $addRelation): void
    {
        $rows = $this->rows ?? self::loadRows();

        foreach ($rows as $row) {
            $sku = (string) ($row['sku'] ?? '?');

            if ($problems = self::problems($row)) {
                throw new RuntimeException("ProductsSeeder: row {$sku} rejected before writing: ".implode('; ', $problems));
            }
        }

        $this->stats = ['rows' => count($rows), 'created' => 0, 'skipped_existing' => 0, 'skipped_trashed' => 0, 'skipped_recorded' => 0, 'invalid' => 0, 'suspicious_price' => 0, 'without_price' => 0, 'fractional_price' => 0, 'relations' => 0];
        $this->warnings = [];

        CategoryTree::flush();

        $categories = Category::query()->pluck('id', 'slug');
        $brands = Brand::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name): array => [mb_strtolower($name) => $id]);
        $attributes = Attribute::query()->with('options')->get()->keyBy('code');
        $rollTypes = RollType::query()->get()->keyBy(fn (RollType $rollType): string => $rollType->labels_per_roll.'@'.$rollType->core_diameter_mm);
        $recorded = array_flip(self::recordedSkus());
        $createdNow = [];
        $fractionalSkus = [];

        // One query for every SKU in the data, trashed products included (true = trashed).
        $existingSkus = [];
        $wanted = array_values(array_unique(array_map(fn (array $row): string => mb_strtoupper(trim((string) $row['sku'])), $rows)));

        foreach (array_chunk($wanted, 500) as $chunk) {
            foreach (Product::withTrashed()->whereIn('sku', $chunk)->get(['sku', 'deleted_at']) as $found) {
                $existingSkus[mb_strtoupper($found->sku)] = $found->trashed();
            }
        }

        try {
            foreach ($rows as $row) {
                $sku = mb_strtoupper(trim((string) $row['sku']));

                if (isset($recorded[$sku])) {
                    $this->stats['skipped_recorded']++;

                    continue;
                }

                if (array_key_exists($sku, $existingSkus)) {
                    $this->stats[$existingSkus[$sku] ? 'skipped_trashed' : 'skipped_existing']++;

                    continue;
                }

                $categoryId = $categories[$row['category']] ?? null;

                if ($categoryId === null) {
                    $this->stats['invalid']++;
                    Log::warning("ProductsSeeder: {$sku} skipped, unknown category");

                    continue;
                }

                $suspicious = (bool) ($row['suspicious_price'] ?? false);
                $price = null;

                if (! $suspicious && ($row['price'] ?? null)) {
                    $price = (int) round($row['price'] * 100);
                }

                if ($suspicious) {
                    $this->stats['suspicious_price']++;
                }

                // A per-label price must be whole soʻm (plan 08): not guessed or rounded, the product starts without a price.
                if ($price !== null && ($row['pricing_unit'] ?? 'unit') === 'label' && $price % 100 !== 0) {
                    $price = null;
                    $this->stats['fractional_price']++;
                    $fractionalSkus[] = $sku;
                    Log::warning("ProductsSeeder: {$sku} per-label price is not a whole number of soʻm, saved without a price");
                }

                if ($price === null) {
                    $this->stats['without_price']++;
                }

                $data = [
                    'type' => ProductType::from($row['type']),
                    'category_id' => (int) $categoryId,
                    'brand_id' => filled($row['brand'] ?? null) ? ($brands[mb_strtolower($row['brand'])] ?? null) : null,
                    'sku' => $sku,
                    'model' => $row['model'] ?? null,
                    'name' => $row['name'],
                    'short_description' => $row['short_description'] ?? [],
                    'description' => $row['description'] ?? [],
                    'meta_title' => $row['meta_title'] ?? [],
                    'meta_description' => $row['meta_description'] ?? [],
                    'price' => $price,
                    'price_dealer' => null,
                    'price_wholesale' => null,
                    'pricing_unit' => ProductPricingUnit::from($row['pricing_unit'] ?? 'unit'),
                    'roll_type_ids' => $this->rollTypeIds($sku, $row['roll_types'] ?? [], $rollTypes),
                    'unit' => ProductUnit::from($row['unit']),
                    'min_qty' => max(1, (int) ($row['min_qty'] ?? 1)),
                    'sale_mode' => SaleMode::from($row['sale_mode'] ?? 'quote'),
                    'stock_status' => StockStatus::from($row['stock_status'] ?? 'on_order'),
                    'warranty_months' => $row['warranty_months'] ?? null,
                    'badge' => null,
                    'is_active' => false,
                    'is_featured' => false,
                ];

                try {
                    $product = $saveProduct->handle(null, $data, $this->specs($sku, $row['specs'] ?? [], $attributes));
                } catch (ValidationException $exception) {
                    $this->stats['invalid']++;
                    Log::warning("ProductsSeeder: {$sku} failed validation on: ".implode(', ', array_keys($exception->errors())));

                    continue;
                }

                $createdNow[$product->sku] = true;
                $this->stats['created']++;
            }
        } finally {
            // Recorded once per run, also when a row throws (S3).
            $this->record(array_keys($createdNow));
        }

        $this->linkRelations($rows, $addRelation, $createdNow);

        CategoryTree::flush();
        Sitemap::flush();
        LlmsTxt::flush();

        $summary = collect($this->stats)->map(fn (int $count, string $key): string => "{$key}={$count}")->implode(' ');

        if ($fractionalSkus !== []) {
            $summary .= ' fractional_price_skus='.implode(',', $fractionalSkus);
        }

        Log::info("ProductsSeeder: {$summary}");
        $this->command?->info("ProductsSeeder: {$summary}");
    }

    /**
     * SKUs this seeder created so far (system setting RECORD_KEY).
     *
     * @return list<string>
     */
    public static function recordedSkus(): array
    {
        $value = Setting::query()->where('key', self::RECORD_KEY)->value('value');

        return is_array($value) ? array_values(array_map('strval', $value['skus'] ?? [])) : [];
    }

    /**
     * Merge the SKUs created in this run into the record, under a row lock.
     *
     * @param  list<string>  $skus
     */
    private function record(array $skus): void
    {
        if ($skus === []) {
            return;
        }

        DB::transaction(function () use ($skus): void {
            $setting = Setting::query()->where('key', self::RECORD_KEY)->lockForUpdate()->first();
            $current = is_array($setting?->value) ? array_map('strval', $setting->value['skus'] ?? []) : [];

            Setting::query()->updateOrCreate(['key' => self::RECORD_KEY], [
                'group' => 'system',
                'value' => ['skus' => array_values(array_unique([...$current, ...$skus]))],
            ]);
        });
    }

    /**
     * Links between products that exist (a link to an unknown SKU is dropped); only for
     * products created in this run.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, true>  $createdNow  SKUs created in this run
     */
    private function linkRelations(array $rows, AddProductRelation $addRelation, array $createdNow): void
    {
        $skus = [];

        foreach ($rows as $row) {
            foreach ([$row['sku'], ...array_column($row['related'] ?? [], 'sku')] as $sku) {
                $skus[] = mb_strtoupper(trim((string) $sku));
            }
        }

        $products = Product::query()->whereIn('sku', array_unique($skus))->get()->keyBy('sku');

        foreach ($rows as $row) {
            $product = $products->get(mb_strtoupper(trim((string) $row['sku'])));

            if ($product === null || ! isset($createdNow[$product->sku])) {
                continue;
            }

            foreach ($row['related'] ?? [] as $link) {
                $related = $products->get(mb_strtoupper(trim((string) $link['sku'])));
                $type = RelationType::tryFrom((string) ($link['type'] ?? ''));

                if ($related === null || $type === null || $related->is($product)) {
                    continue;
                }

                $exists = ProductRelation::query()
                    ->where('product_id', $product->id)
                    ->where('related_id', $related->id)
                    ->where('type', $type)
                    ->exists();

                if (! $exists) {
                    $addRelation->handle($product, $related, $type);
                    $this->stats['relations']++;
                }
            }
        }
    }
}
