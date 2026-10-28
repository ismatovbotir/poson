<?php

namespace App\Livewire\Forms\Admin\Products;

use App\Enums\AttributeType;
use App\Enums\ProductBadge;
use App\Enums\ProductPricingUnit;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\ResolvedAttribute;
use App\Support\Money;
use App\Support\Slug;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * State and validation of the product form. The component owns the product
 * ($this->component->product); saving goes through SaveProduct.
 *
 * Prices are typed in whole soʻm and converted to tiyin here (productData()),
 * never in the view. Spec rows keep one value slot per attribute type
 * (text per locale, number, bool, option, options); SaveProduct picks the
 * slot of the attribute's type.
 *
 * Label products (plan 08): the prices are per one label (whole soʻm), the
 * unit is roll, roll_type_ids are the attached roll types in picker order.
 *
 * @phpstan-type SpecRow array{attribute_id: int, extra: bool, skipped: bool, is_key: bool, text: array<string, string>, number: string, bool: string, option: string, options: list<int>}
 */
class ProductForm extends Form
{
    /** @var array<string, string> */
    public array $name = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $short_description = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $description = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_description = ['uz' => '', 'ru' => '', 'en' => ''];

    public string $type = 'hardware';

    /**
     * Category id as a string ('' = none yet), as the select sends it.
     */
    public string $category_id = '';

    public string $brand_id = '';

    public string $sku = '';

    public string $model = '';

    /**
     * Stored slug, display only: never validated nor sent to the action
     * (generated once from the en name, else uz, then fixed).
     */
    public string $slug = '';

    /**
     * Retail price in whole soʻm ('' = price on request).
     */
    public string $price = '';

    public string $price_dealer = '';

    public string $price_wholesale = '';

    /**
     * 'unit' or 'label' (plan 08; label: consumables only, prices per one label).
     */
    public string $pricing_unit = 'unit';

    /**
     * Roll types of a label product, in picker order (the first is preselected on the site).
     *
     * @var list<int>
     */
    public array $roll_type_ids = [];

    public string $unit = 'piece';

    public string $min_qty = '1';

    public string $sale_mode = 'both';

    public string $stock_status = 'in_stock';

    public string $warranty_months = '';

    /**
     * '' (none), 'hit' or 'new'.
     */
    public string $badge = '';

    /**
     * New products start as drafts.
     */
    public bool $is_active = false;

    public bool $is_featured = false;

    /**
     * Spec rows in display order (category rows, then extras).
     *
     * @var list<SpecRow>
     */
    public array $specs = [];

    /**
     * @param  list<ResolvedAttribute>  $rows  ProductAttributeResolver::forProduct()
     */
    public function setProduct(Product $product, array $rows): void
    {
        $this->name = $this->locales($product->getTranslations('name'));
        $this->short_description = $this->locales($product->getTranslations('short_description'));
        $this->description = $this->locales($product->getTranslations('description'));
        $this->meta_title = $this->locales($product->getTranslations('meta_title'));
        $this->meta_description = $this->locales($product->getTranslations('meta_description'));
        $this->type = $product->type->value;
        $this->category_id = (string) $product->category_id;
        $this->brand_id = (string) ($product->brand_id ?? '');
        $this->sku = $product->sku;
        $this->model = (string) $product->model;
        $this->slug = $product->slug;
        $this->price = Money::tiyinToSums($product->price);
        $this->price_dealer = Money::tiyinToSums($product->price_dealer);
        $this->price_wholesale = Money::tiyinToSums($product->price_wholesale);
        $this->pricing_unit = $product->pricing_unit->value;
        $this->roll_type_ids = $product->rollTypes()->pluck('roll_types.id')->map(fn ($id): int => (int) $id)->values()->all();
        $this->unit = $product->unit->value;
        $this->min_qty = (string) $product->min_qty;
        $this->sale_mode = $product->sale_mode->value;
        $this->stock_status = $product->stock_status->value;
        $this->warranty_months = (string) ($product->warranty_months ?? '');
        $this->badge = (string) $product->badge?->value;
        $this->is_active = $product->is_active;
        $this->is_featured = $product->is_featured;

        $values = $product->attributeValues()->get()->groupBy('attribute_id');

        $this->specs = array_map(
            fn (ResolvedAttribute $row): array => $this->rowFromValues($row, $values->get($row->attribute->id, collect())),
            $rows,
        );
    }

    /**
     * An empty spec row.
     *
     * @return SpecRow
     */
    public static function blankRow(int $attributeId, bool $extra = false): array
    {
        return [
            'attribute_id' => $attributeId,
            'extra' => $extra,
            'skipped' => false,
            'is_key' => false,
            'text' => ['uz' => '', 'ru' => '', 'en' => ''],
            'number' => '',
            'bool' => '',
            'option' => '',
            'options' => [],
        ];
    }

    /**
     * Whether a row holds a value for its attribute type.
     *
     * @param  array<string, mixed>  $row
     */
    public static function rowHasValue(array $row, AttributeType $type): bool
    {
        return match ($type) {
            AttributeType::Text => trim(implode('', (array) ($row['text'] ?? []))) !== '',
            AttributeType::Number => trim((string) ($row['number'] ?? '')) !== '',
            AttributeType::Boolean => ($row['bool'] ?? '') !== '' && ($row['bool'] ?? null) !== null,
            AttributeType::Select => (int) ($row['option'] ?? 0) > 0,
            AttributeType::Multiselect => array_filter((array) ($row['options'] ?? [])) !== [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $price = ['nullable', 'string', 'max:24', function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && Money::sumsToTiyin((string) $value) === null) {
                $fail(__('admin.products.errors.price_invalid'));
            }
        }];

        return [
            'name.uz' => ['required', 'string', 'max:255'],
            'name.ru' => ['nullable', 'string', 'max:255'],
            'name.en' => ['nullable', 'string', 'max:255'],
            'short_description.*' => ['nullable', 'string', 'max:500'],
            'description.*' => ['nullable', 'string', 'max:50000'],
            'meta_title.*' => ['nullable', 'string', 'max:255'],
            'meta_description.*' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::enum(ProductType::class)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._\/+ -]*$/', Rule::unique('products', 'sku')->ignore($this->component->product?->id)],
            'model' => ['nullable', 'string', 'max:255'],
            'price' => $price,
            'price_dealer' => $price,
            'price_wholesale' => $price,
            'pricing_unit' => ['required', Rule::enum(ProductPricingUnit::class)],
            'roll_type_ids' => ['array', 'max:50'],
            'roll_type_ids.*' => ['integer', 'distinct'],
            'unit' => ['required', Rule::enum(ProductUnit::class)],
            'min_qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'sale_mode' => ['required', Rule::enum(SaleMode::class)],
            'stock_status' => ['required', Rule::enum(StockStatus::class)],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'badge' => ['nullable', Rule::enum(ProductBadge::class)],
            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'specs' => ['array', 'max:300'],
            'specs.*.attribute_id' => ['required', 'integer', 'distinct'],
            'specs.*.skipped' => ['boolean'],
            'specs.*.is_key' => ['boolean'],
            'specs.*.text.*' => ['nullable', 'string', 'max:1000'],
            'specs.*.number' => ['nullable', 'string', 'max:32'],
            'specs.*.bool' => ['nullable', 'in:0,1'],
            'specs.*.option' => ['nullable', 'integer'],
            'specs.*.options' => ['array'],
            'specs.*.options.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'type' => __('admin.products.fields.type'),
            'category_id' => __('admin.products.fields.category'),
            'brand_id' => __('admin.products.fields.brand'),
            'sku' => __('admin.products.fields.sku'),
            'model' => __('admin.products.fields.model'),
            'price' => __('enums.price_level.retail'),
            'price_dealer' => __('enums.price_level.dealer'),
            'price_wholesale' => __('enums.price_level.wholesale'),
            'unit' => __('admin.products.fields.unit'),
            'pricing_unit' => __('admin.products.fields.pricing_unit'),
            'roll_type_ids' => __('admin.products.roll_types.title'),
            'min_qty' => __('admin.products.fields.min_qty'),
            'sale_mode' => __('admin.products.fields.sale_mode'),
            'stock_status' => __('admin.products.fields.stock_status'),
            'warranty_months' => __('admin.products.fields.warranty_months'),
            'badge' => __('admin.products.fields.badge'),
            'specs.*.number' => __('admin.products.specs.value'),
            'specs.*.text.*' => __('admin.products.specs.value'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $suffix = ' ('.strtoupper($locale).')';
            $attributes["name.{$locale}"] = __('admin.catalog.name').$suffix;
            $attributes["short_description.{$locale}"] = __('admin.products.fields.short_description').$suffix;
            $attributes["description.{$locale}"] = __('admin.catalog.description').$suffix;
            $attributes["meta_title.{$locale}"] = __('admin.catalog.meta_title').$suffix;
            $attributes["meta_description.{$locale}"] = __('admin.catalog.meta_description').$suffix;
        }

        return $attributes;
    }

    /**
     * Slug SaveProduct generates on create (live preview while the record has none).
     */
    public function autoSlug(): string
    {
        return Slug::make(filled($this->name['en'] ?? '') ? $this->name['en'] : ($this->name['uz'] ?? ''));
    }

    /**
     * Input for SaveProduct (prices converted from whole soʻm to tiyin).
     *
     * @return array<string, mixed>
     */
    public function productData(): array
    {
        return [
            'type' => ProductType::from($this->type),
            'category_id' => (int) $this->category_id,
            'brand_id' => filled($this->brand_id) ? (int) $this->brand_id : null,
            'sku' => $this->sku,
            'model' => filled($this->model) ? $this->model : null,
            'name' => $this->name,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'price' => $this->priceInTiyin('price'),
            'price_dealer' => $this->priceInTiyin('price_dealer'),
            'price_wholesale' => $this->priceInTiyin('price_wholesale'),
            'pricing_unit' => ProductPricingUnit::from($this->pricing_unit),
            'roll_type_ids' => array_values(array_map('intval', $this->roll_type_ids)),
            'unit' => $this->pricing_unit === ProductPricingUnit::Label->value ? ProductUnit::Roll : ProductUnit::from($this->unit),
            'min_qty' => (int) $this->min_qty,
            'sale_mode' => SaleMode::from($this->sale_mode),
            'stock_status' => StockStatus::from($this->stock_status),
            'warranty_months' => filled($this->warranty_months) ? (int) $this->warranty_months : null,
            'badge' => filled($this->badge) ? ProductBadge::from($this->badge) : null,
            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
        ];
    }

    /**
     * A price input in tiyin. An unchanged input keeps the stored tiyin, so a
     * price with tiyin below one soʻm (e.g. imported) survives saving the form,
     * which only shows whole soʻm.
     */
    private function priceInTiyin(string $field): ?int
    {
        $typed = Money::sumsToTiyin($this->{$field});
        $product = $this->component->product ?? null;

        if ($product instanceof Product) {
            $stored = $product->getAttribute($field);

            if ($typed === Money::sumsToTiyin(Money::tiyinToSums($stored))) {
                return $stored;
            }
        }

        return $typed;
    }

    /**
     * Spec input for SaveProduct.
     *
     * @return list<array<string, mixed>>
     */
    public function specInput(): array
    {
        return array_values(array_map(fn (array $row): array => [
            'attribute_id' => (int) $row['attribute_id'],
            'skipped' => (bool) ($row['skipped'] ?? false),
            'is_key' => (bool) ($row['is_key'] ?? false),
            'text' => (array) ($row['text'] ?? []),
            'number' => (string) ($row['number'] ?? ''),
            'bool' => ($row['bool'] ?? '') === '' ? null : (string) $row['bool'],
            'option' => filled($row['option'] ?? null) ? (int) $row['option'] : null,
            'options' => array_values(array_map('intval', (array) ($row['options'] ?? []))),
        ], $this->specs));
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     * @return SpecRow
     */
    private function rowFromValues(ResolvedAttribute $resolved, Collection $values): array
    {
        $row = self::blankRow($resolved->attribute->id, $resolved->isExtra());
        $row['skipped'] = $resolved->skipped;
        $row['is_key'] = $values->contains(fn (ProductAttributeValue $value): bool => $value->is_key);
        $first = $values->first();

        if ($first === null) {
            return $row;
        }

        match ($resolved->attribute->type) {
            AttributeType::Text => $row['text'] = $this->locales($first->getTranslations('value_text')),
            AttributeType::Number => $row['number'] = (string) $first->numberForDisplay(),
            AttributeType::Boolean => $row['bool'] = $first->value_bool === null ? '' : ($first->value_bool ? '1' : '0'),
            AttributeType::Select => $row['option'] = (string) ($first->attribute_option_id ?? ''),
            AttributeType::Multiselect => $row['options'] = $values->pluck('attribute_option_id')->filter()->map(fn ($id): int => (int) $id)->values()->all(),
        };

        return $row;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private function locales(array $values): array
    {
        $result = [];

        foreach (config('app.supported_locales') as $locale) {
            $result[$locale] = (string) ($values[$locale] ?? '');
        }

        return $result;
    }
}
