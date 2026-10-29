<?php

namespace App\Actions\Catalog;

use App\Actions\Quotes\SaveQuoteItems;
use App\Enums\AttributeType;
use App\Enums\ProductBadge;
use App\Enums\ProductPricingUnit;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttributeSkip;
use App\Models\ProductAttributeValue;
use App\Models\RollType;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ProductSearchText;
use App\Support\Catalog\ResolvedAttribute;
use App\Support\HtmlSanitizer;
use App\Support\Orders\CheckoutSettings;
use App\Support\Slug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a product with its spec values and skips, in one
 * transaction (images, documents and relations have their own actions).
 *
 * - Prices arrive in tiyin (the form converts whole soʻm). A null Retail
 *   price means "price on request" and forces sale_mode = quote.
 * - SKU is trimmed and upper-cased; SKU and slug are unique including
 *   trashed products. The slug is generated once from the en name, else
 *   uz, and never changes after that (incoming slugs are ignored).
 * - Descriptions are sanitized (HtmlSanitizer).
 * - Spec rows (ProductAttributeResolver: category chain + extras): one value
 *   row per attribute, a multiselect one row per option; empty values are
 *   not stored. Optional category attributes can be skipped, required ones
 *   can't. At most Product::MAX_KEY_SPECS attributes are key specs.
 * - Required specs are enforced only when the product is being activated
 *   (new and active, or draft → active). Drafts may be incomplete, and an
 *   already active product stays active (it shows as "specs incomplete").
 * - search_text and has_missing_specs are recomputed.
 * - Price API (plan 09): for a product with price_synced_at set, the
 *   incoming price fields are replaced by the stored ones, re-read under a
 *   row lock inside the transaction (the API wins, also against a batch
 *   that arrived after the form was loaded), and the SKU can't change
 *   (unlink first).
 * - Label rolls (plan 08): pricing_unit = label only for consumables; the
 *   unit is then forced to roll (min_qty counts rolls) and every non-null
 *   price level is per one label in whole soʻm (tiyin % 100 = 0). roll_type_ids
 *   (ordered) are synced to product_roll_type with sort 1, 2, ... (also for a
 *   price-synced product); an active label product needs at least one active
 *   roll type (min_qty at most SaveQuoteItems::MAX_QTY rolls). A unit-priced
 *   product keeps no roll types. The pricing unit of a price-synced product
 *   is locked like its prices (re-read under the row lock). Without the
 *   roll_type_ids key an existing product's roll types stay as they are.
 *
 * Errors are ValidationExceptions keyed like the form ("sku",
 * "specs.3.number", ...); spec indexes are positions in $specs.
 */
class SaveProduct
{
    /**
     * Largest price in tiyin (10^15 soʻm is far beyond any real price).
     */
    public const MAX_PRICE = 100_000_000_000_000_000;

    /**
     * SKU after upper-casing: ASCII letters, digits and . _ / + - space.
     */
    public const SKU_PATTERN = '/^[A-Z0-9][A-Z0-9._\/+ -]*$/';

    public function __construct(private ProductAttributeResolver $resolver) {}

    /**
     * @param  array{
     *     type: ProductType,
     *     category_id: int,
     *     brand_id: int|null,
     *     sku: string,
     *     model: string|null,
     *     name: array<string, string|null>,
     *     short_description: array<string, string|null>,
     *     description: array<string, string|null>,
     *     meta_title: array<string, string|null>,
     *     meta_description: array<string, string|null>,
     *     price: int|null,
     *     price_dealer: int|null,
     *     price_wholesale: int|null,
     *     pricing_unit?: ProductPricingUnit,
     *     roll_type_ids?: list<int>|null,
     *     unit: ProductUnit,
     *     min_qty: int,
     *     sale_mode: SaleMode,
     *     stock_status: StockStatus,
     *     warranty_months: int|null,
     *     badge: ProductBadge|null,
     *     is_active: bool,
     *     is_featured: bool,
     *     ikpu_code?: string|null,
     *     package_code?: string|null
     * }  $data
     * @param  list<array{
     *     attribute_id: int,
     *     skipped?: bool,
     *     is_key?: bool,
     *     text?: array<string, string|null>,
     *     number?: string|int|float|null,
     *     bool?: bool|string|null,
     *     option?: int|string|null,
     *     options?: list<int|string>
     * }>  $specs  value keys by attribute type: text, number, bool, option (select), options (multiselect)
     *
     * @throws ValidationException
     */
    public function handle(?Product $product, array $data, array $specs = []): Product
    {
        $product ??= new Product;

        foreach (['ikpu_code' => CheckoutSettings::IKPU, 'package_code' => CheckoutSettings::PACKAGE_CODE] as $code => $pattern) {
            if (filled($data[$code] ?? null) && ! preg_match($pattern, trim((string) $data[$code]))) {
                throw ValidationException::withMessages([$code => __('validation.regex', ['attribute' => __('admin.products.fields.'.$code)])]);
            }
        }

        $wasActive = $product->exists && (bool) $product->getOriginal('is_active');

        // The price API owns the prices of a synced product: incoming price fields are ignored,
        // and so is the pricing unit (it says what those prices are per).
        if ($product->exists && $product->getOriginal('price_synced_at') !== null) {
            foreach (['price', 'price_dealer', 'price_wholesale', 'pricing_unit'] as $field) {
                $data[$field] = $product->getOriginal($field);
            }
        }

        $data['pricing_unit'] ??= $product->exists ? $product->pricing_unit : ProductPricingUnit::Unit;
        $data = $this->applyPricingUnit($data);

        $this->checkFields($product, $data);
        $rollTypeIds = $this->rollTypeIds($product, $data);

        $sku = mb_strtoupper(trim($data['sku']));
        $generated = ! ($product->exists && (string) $product->getOriginal('slug') !== '');
        $slug = $this->slug($product, $data);
        [$rows, $missing] = $this->specRows($data['category_id'], $specs);

        if ($data['is_active'] && ! $wasActive && $missing !== []) {
            $this->failMissing($missing, $specs);
        }

        try {
            return $this->store($product, $data, $sku, $slug, $generated, $rows, $missing, $rollTypeIds);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent save took the SKU between the check and the insert (a taken
            // generated slug is regenerated in store(); a stored one never clashes).
            if (Slug::violates($exception, 'sku')) {
                throw ValidationException::withMessages(['sku' => __('validation.unique', ['attribute' => __('admin.products.fields.sku')])]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{row: ResolvedAttribute, skipped: bool, is_key: bool, values: list<array<string, mixed>>}>  $rows
     * @param  list<ResolvedAttribute>  $missing
     * @param  list<int>|null  $rollTypeIds  null = leave the pivot as it is
     */
    private function store(Product $product, array $data, string $sku, string $slug, bool $generated, array $rows, array $missing, ?array $rollTypeIds): Product
    {
        return DB::transaction(function () use ($product, $data, $sku, $slug, $generated, $rows, $missing, $rollTypeIds): Product {
            if ($product->exists) {
                $pricingUnit = $data['pricing_unit'];
                $data = $this->lockedPrices($product, $data);

                // A price batch arrived after the form was loaded and the pricing unit changed in the
                // form: the stored unit wins, so the unit, the label checks and the roll types follow it.
                if ($data['pricing_unit'] !== $pricingUnit) {
                    $data = $this->applyPricingUnit($data);
                    $this->checkFields($product, $data);
                    $rollTypeIds = $this->rollTypeIds($product, $data);
                }
            }

            $product->storeTranslations('name', $data['name']);
            $product->storeTranslations('short_description', $data['short_description']);
            $product->storeTranslations('description', array_map(HtmlSanitizer::cleanOrNull(...), $data['description']));
            $product->storeTranslations('meta_title', $data['meta_title']);
            $product->storeTranslations('meta_description', $data['meta_description']);

            $product->fill([
                'type' => $data['type'],
                'category_id' => $data['category_id'],
                'brand_id' => $data['brand_id'],
                'sku' => $sku,
                'model' => filled($data['model']) ? trim((string) $data['model']) : null,
                'slug' => $slug,
                'price' => $data['price'],
                'price_dealer' => $data['price_dealer'],
                'price_wholesale' => $data['price_wholesale'],
                'pricing_unit' => $data['pricing_unit'],
                'unit' => $data['unit'],
                'min_qty' => $data['min_qty'],
                'sale_mode' => $data['price'] === null ? SaleMode::Quote : $data['sale_mode'],
                'stock_status' => $data['stock_status'],
                'warranty_months' => $data['warranty_months'],
                'badge' => $data['badge'],
                'is_active' => $data['is_active'],
                'is_featured' => $data['is_featured'],
                'has_missing_specs' => $missing !== [],
            ]);

            // Plan 11b fiscal codes (optional keys: callers without them leave the stored codes).
            foreach (['ikpu_code', 'package_code'] as $code) {
                if (array_key_exists($code, $data)) {
                    $product->{$code} = filled($data[$code]) ? trim((string) $data[$code]) : null;
                }
            }

            $product->search_text = ProductSearchText::build($product);

            // A product created concurrently with the same name: next free slug, once.
            Slug::saveGenerated($product, $generated, fn (string $clashed): string => $this->slug($product, $data, $clashed));

            $this->storeSpecs($product, $rows);

            if ($rollTypeIds !== null) {
                $product->rollTypes()->sync(collect($rollTypeIds)->values()->mapWithKeys(fn (int $id, int $index): array => [$id => ['sort' => $index + 1]])->all());
                $product->unsetRelation('rollTypes');
            }

            return $product;
        });
    }

    /**
     * Re-read the row under a lock: if the price API owns the prices (also
     * when a batch arrived after the form was loaded), keep the stored ones
     * and the stored pricing unit.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function lockedPrices(Product $product, array $data): array
    {
        $row = DB::table('products')
            ->where('id', $product->id)
            ->lockForUpdate()
            ->first(['price', 'price_dealer', 'price_wholesale', 'pricing_unit', 'price_synced_at']);

        if ($row === null || $row->price_synced_at === null) {
            return $data;
        }

        foreach (['price', 'price_dealer', 'price_wholesale'] as $field) {
            $data[$field] = $row->{$field} !== null ? (int) $row->{$field} : null;
        }

        $data['pricing_unit'] = ProductPricingUnit::from((string) $row->pricing_unit);

        return $data;
    }

    /**
     * Label pricing sells in rolls: the unit is forced to roll.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyPricingUnit(array $data): array
    {
        if ($data['pricing_unit'] === ProductPricingUnit::Label) {
            $data['unit'] = ProductUnit::Roll;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function checkFields(Product $product, array $data): void
    {
        $errors = [];

        if (trim((string) ($data['name']['uz'] ?? '')) === '') {
            $errors['name.uz'] = __('validation.required', ['attribute' => __('admin.catalog.name').' (UZ)']);
        }

        $sku = mb_strtoupper(trim((string) $data['sku']));

        if ($product->exists && $product->getOriginal('price_synced_at') !== null && $sku !== mb_strtoupper((string) $product->getOriginal('sku'))) {
            // The price API matches on the SKU: changing it would orphan the product's prices.
            $errors['sku'] = __('api.product.sku_locked');
        } elseif ($sku === '') {
            $errors['sku'] = __('validation.required', ['attribute' => __('admin.products.fields.sku')]);
        } elseif (! preg_match(self::SKU_PATTERN, $sku) || mb_strlen($sku) > 64) {
            $errors['sku'] = __('validation.regex', ['attribute' => __('admin.products.fields.sku')]);
        } elseif (Product::withTrashed()->where('sku', $sku)->when($product->exists, fn ($query) => $query->whereKeyNot($product->id))->exists()) {
            $errors['sku'] = __('validation.unique', ['attribute' => __('admin.products.fields.sku')]);
        }

        if (CategoryTree::find((int) $data['category_id']) === null) {
            $errors['category_id'] = __('validation.exists', ['attribute' => __('admin.products.fields.category')]);
        }

        if ($data['brand_id'] !== null && ! Brand::query()->whereKey($data['brand_id'])->exists()) {
            $errors['brand_id'] = __('validation.exists', ['attribute' => __('admin.products.fields.brand')]);
        }

        foreach (['price', 'price_dealer', 'price_wholesale'] as $field) {
            $price = $data[$field];

            if ($price !== null && ($price < 0 || $price > self::MAX_PRICE)) {
                $errors[$field] = __('admin.products.errors.price_invalid');
            }
        }

        if ($data['pricing_unit'] === ProductPricingUnit::Label) {
            if ($data['type'] !== ProductType::Consumable) {
                $errors['pricing_unit'] = __('admin.products.errors.label_requires_consumable');
            }

            foreach (['price', 'price_dealer', 'price_wholesale'] as $field) {
                if (! isset($errors[$field]) && $data[$field] !== null && $data[$field] % 100 !== 0) {
                    $errors[$field] = __('admin.products.errors.label_whole_som');
                }
            }
        }

        // A label product's minimum is in rolls and must fit a quote line.
        if ($data['pricing_unit'] === ProductPricingUnit::Label && $data['min_qty'] > SaveQuoteItems::MAX_QTY) {
            $errors['min_qty'] = __('validation.max.numeric', ['attribute' => __('admin.products.fields.min_qty'), 'max' => SaveQuoteItems::MAX_QTY]);
        }

        if ($data['min_qty'] < 1) {
            $errors['min_qty'] = __('validation.min.numeric', ['attribute' => __('admin.products.fields.min_qty'), 'min' => 1]);
        }

        if ($data['warranty_months'] !== null && ($data['warranty_months'] < 0 || $data['warranty_months'] > 600)) {
            $errors['warranty_months'] = __('validation.between.numeric', ['attribute' => __('admin.products.fields.warranty_months'), 'min' => 0, 'max' => 600]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The roll types to sync (ordered, unique), or null to leave them: a label
     * product gets the given ones (all must exist; when active at least one
     * of them must be active), a unit-priced product none. Without the
     * roll_type_ids key an existing label product keeps its roll types (an
     * active one must already have an active roll type).
     *
     * @param  array<string, mixed>  $data
     * @return list<int>|null
     *
     * @throws ValidationException
     */
    private function rollTypeIds(Product $product, array $data): ?array
    {
        if ($data['pricing_unit'] !== ProductPricingUnit::Label) {
            return [];
        }

        $given = $data['roll_type_ids'] ?? null;

        if ($given === null && $product->exists) {
            if ($data['is_active'] && ! $product->activeRollTypes()->exists()) {
                throw ValidationException::withMessages(['roll_type_ids' => __('admin.products.errors.label_needs_roll_types')]);
            }

            return null;
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($given ?? []))));
        $known = RollType::query()->whereKey($ids)->pluck('is_active', 'id');

        if (count($ids) !== $known->count()) {
            throw ValidationException::withMessages(['roll_type_ids' => __('admin.products.errors.roll_type_invalid')]);
        }

        if ($data['is_active'] && ! $known->contains(true)) {
            throw ValidationException::withMessages(['roll_type_ids' => __('admin.products.errors.label_needs_roll_types')]);
        }

        return $ids;
    }

    /**
     * The stored slug once there is one (never changed, whatever the input);
     * otherwise a unique slug from the en name, else uz (never $except, a slug
     * that just clashed). Incoming slugs are ignored.
     *
     * @param  array{name: array<string, string|null>}  $data
     */
    private function slug(Product $product, array $data, ?string $except = null): string
    {
        $stored = (string) $product->getOriginal('slug');

        if ($product->exists && $stored !== '') {
            return $stored;
        }

        $ignoreId = $product->exists ? $product->id : null;
        $source = filled($data['name']['en'] ?? null) ? $data['name']['en'] : ($data['name']['uz'] ?? '');

        return Slug::uniqueFor(Product::class, Slug::make($source), $ignoreId, $except);
    }

    /**
     * Resolve the spec input against the category chain.
     *
     * Returns the rows to store and the required rows without a value.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return array{0: list<array{row: ResolvedAttribute, skipped: bool, is_key: bool, values: list<array<string, mixed>>}>, 1: list<ResolvedAttribute>}
     *
     * @throws ValidationException
     */
    private function specRows(int $categoryId, array $specs): array
    {
        $inputs = [];
        $indexes = [];

        foreach (array_values($specs) as $index => $spec) {
            $id = (int) ($spec['attribute_id'] ?? 0);

            if ($id > 0 && ! isset($inputs[$id])) {
                $inputs[$id] = $spec;
                $indexes[$id] = $index;
            }
        }

        $resolved = $this->resolver->resolve($categoryId, [], array_keys($inputs));
        $errors = [];
        $rows = [];
        $missing = [];
        $keys = 0;

        foreach ($resolved as $row) {
            $id = $row->attribute->id;
            $input = $inputs[$id] ?? [];
            $field = isset($indexes[$id]) ? "specs.{$indexes[$id]}" : 'specs';
            $wantsSkip = (bool) ($input['skipped'] ?? false);

            if ($wantsSkip && $row->required) {
                $errors[$field] = __('admin.products.errors.required_not_skippable', ['name' => $row->attribute->name]);

                continue;
            }

            $skipped = $wantsSkip && ! $row->isExtra();
            $values = $skipped ? [] : $this->values($row->attribute, $input, $field, $errors);
            // A key spec without a value shows nothing: it neither counts nor is stored.
            $isKey = ! $skipped && $values !== [] && (bool) ($input['is_key'] ?? false);
            $keys += $isKey ? 1 : 0;

            if ($row->required && $values === []) {
                $missing[] = $row;
            }

            $rows[] = ['row' => $row, 'skipped' => $skipped, 'is_key' => $isKey, 'values' => $values];
        }

        if ($keys > Product::MAX_KEY_SPECS) {
            $errors['specs'] = __('admin.products.errors.too_many_keys', ['max' => Product::MAX_KEY_SPECS]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$rows, $missing];
    }

    /**
     * Value rows (column => value) for one attribute; [] when empty.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private function values(Attribute $attribute, array $input, string $field, array &$errors): array
    {
        return match ($attribute->type) {
            AttributeType::Text => $this->textValue($input, $field, $errors),
            AttributeType::Number => $this->numberValue($input, $field, $errors),
            AttributeType::Boolean => $this->boolValue($input),
            AttributeType::Select => $this->optionValues($attribute, array_filter([(int) ($input['option'] ?? 0)]), $field, $errors),
            AttributeType::Multiselect => $this->optionValues($attribute, array_map('intval', (array) ($input['options'] ?? [])), $field, $errors),
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private function textValue(array $input, string $field, array &$errors): array
    {
        $text = [];

        foreach (config('app.supported_locales') as $locale) {
            $value = trim((string) (($input['text'] ?? [])[$locale] ?? ''));

            if ($value !== '') {
                $text[$locale] = mb_substr($value, 0, 1000);
            }
        }

        if ($text === []) {
            return [];
        }

        if (! isset($text['uz'])) {
            $errors["{$field}.text.uz"] = __('admin.products.errors.value_uz_required');

            return [];
        }

        return [['value_text' => $text]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private function numberValue(array $input, string $field, array &$errors): array
    {
        $raw = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim((string) ($input['number'] ?? '')));

        if ($raw === '') {
            return [];
        }

        if (! is_numeric($raw) || abs((float) $raw) >= 1e10) {
            $errors["{$field}.number"] = __('admin.products.errors.number_invalid');

            return [];
        }

        return [['value_number' => $raw]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function boolValue(array $input): array
    {
        $value = $input['bool'] ?? null;

        if ($value === null || $value === '') {
            return [];
        }

        return [['value_bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN)]];
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, string>  $errors
     * @return list<array<string, mixed>>
     */
    private function optionValues(Attribute $attribute, array $ids, string $field, array &$errors): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $known = $attribute->options->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (array_diff($ids, $known) !== []) {
            $errors[$field] = __('admin.products.errors.option_invalid');

            return [];
        }

        // Stored in the attribute's option order.
        return array_values(array_map(
            fn (int $id): array => ['attribute_option_id' => $id],
            array_values(array_intersect($known, $ids)),
        ));
    }

    /**
     * @param  list<ResolvedAttribute>  $missing
     * @param  list<array<string, mixed>>  $specs
     *
     * @throws ValidationException
     */
    private function failMissing(array $missing, array $specs): never
    {
        $positions = [];

        foreach (array_values($specs) as $index => $spec) {
            $positions[(int) ($spec['attribute_id'] ?? 0)] ??= $index;
        }

        $errors = [
            'specs' => __('admin.products.errors.missing_specs', [
                'list' => implode(', ', array_map(fn (ResolvedAttribute $row): string => $row->attribute->name, $missing)),
            ]),
        ];

        foreach ($missing as $row) {
            if (isset($positions[$row->attribute->id])) {
                $errors["specs.{$positions[$row->attribute->id]}"] = __('admin.products.errors.value_required');
            }
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * Replace the product's value rows and skips.
     *
     * @param  list<array{row: ResolvedAttribute, skipped: bool, is_key: bool, values: list<array<string, mixed>>}>  $rows
     */
    private function storeSpecs(Product $product, array $rows): void
    {
        ProductAttributeValue::query()->where('product_id', $product->id)->delete();
        ProductAttributeSkip::query()->where('product_id', $product->id)->delete();

        $sort = 0;

        foreach ($rows as $row) {
            if ($row['skipped']) {
                ProductAttributeSkip::query()->create(['product_id' => $product->id, 'attribute_id' => $row['row']->attribute->id]);

                continue;
            }

            foreach ($row['values'] as $value) {
                $model = new ProductAttributeValue([
                    'product_id' => $product->id,
                    'attribute_id' => $row['row']->attribute->id,
                    'attribute_option_id' => $value['attribute_option_id'] ?? null,
                    'value_number' => $value['value_number'] ?? null,
                    'value_bool' => $value['value_bool'] ?? null,
                    'is_key' => $row['is_key'],
                    'sort' => ++$sort,
                ]);

                if (isset($value['value_text'])) {
                    $model->storeTranslations('value_text', $value['value_text']);
                }

                $model->save();
            }
        }
    }
}
