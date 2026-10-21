<?php

namespace App\Actions\Catalog;

use App\Enums\AttributeType;
use App\Enums\ProductBadge;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttributeSkip;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ProductSearchText;
use App\Support\Catalog\ResolvedAttribute;
use App\Support\HtmlSanitizer;
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
 *   trashed products. An empty slug comes from the en name, else uz.
 * - Descriptions are sanitized (HtmlSanitizer).
 * - Spec rows (ProductAttributeResolver: category chain + extras): one value
 *   row per attribute, a multiselect one row per option; empty values are
 *   not stored. Optional category attributes can be skipped, required ones
 *   can't. At most Product::MAX_KEY_SPECS attributes are key specs.
 * - Required specs are enforced only when the product is being activated
 *   (new and active, or draft → active). Drafts may be incomplete, and an
 *   already active product stays active (it shows as "specs incomplete").
 * - search_text and has_missing_specs are recomputed.
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
     *     slug: string|null,
     *     name: array<string, string|null>,
     *     short_description: array<string, string|null>,
     *     description: array<string, string|null>,
     *     meta_title: array<string, string|null>,
     *     meta_description: array<string, string|null>,
     *     price: int|null,
     *     price_dealer: int|null,
     *     price_wholesale: int|null,
     *     unit: ProductUnit,
     *     min_qty: int,
     *     sale_mode: SaleMode,
     *     stock_status: StockStatus,
     *     warranty_months: int|null,
     *     badge: ProductBadge|null,
     *     is_active: bool,
     *     is_featured: bool
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
        $wasActive = $product->exists && (bool) $product->getOriginal('is_active');

        $this->checkFields($product, $data);

        $sku = mb_strtoupper(trim($data['sku']));
        $slug = $this->slug($product, $data);
        [$rows, $missing] = $this->specRows($data['category_id'], $specs);

        if ($data['is_active'] && ! $wasActive && $missing !== []) {
            $this->failMissing($missing, $specs);
        }

        try {
            return $this->store($product, $data, $sku, $slug, $rows, $missing);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent save took the SKU or slug between the check and the insert.
            $field = str_contains(mb_strtolower($exception->getMessage()), 'sku') ? 'sku' : 'slug';
            $label = $field === 'sku' ? __('admin.products.fields.sku') : __('admin.catalog.slug');

            throw ValidationException::withMessages([$field => __('validation.unique', ['attribute' => $label])]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{row: ResolvedAttribute, skipped: bool, is_key: bool, values: list<array<string, mixed>>}>  $rows
     * @param  list<ResolvedAttribute>  $missing
     */
    private function store(Product $product, array $data, string $sku, string $slug, array $rows, array $missing): Product
    {
        return DB::transaction(function () use ($product, $data, $sku, $slug, $rows, $missing): Product {
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

            $product->search_text = ProductSearchText::build($product);
            $product->save();

            $this->storeSpecs($product, $rows);

            return $product;
        });
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

        if ($sku === '') {
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
     * @param  array{name: array<string, string|null>, slug: string|null}  $data
     *
     * @throws ValidationException
     */
    private function slug(Product $product, array $data): string
    {
        $ignoreId = $product->exists ? $product->id : null;
        $given = Slug::make($data['slug'] ?? '');

        if ($given !== '') {
            if (Slug::taken(Product::class, $given, $ignoreId)) {
                throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => __('admin.catalog.slug')])]);
            }

            return $given;
        }

        $source = filled($data['name']['en'] ?? null) ? $data['name']['en'] : ($data['name']['uz'] ?? '');

        return Slug::uniqueFor(Product::class, Slug::make($source), $ignoreId);
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
