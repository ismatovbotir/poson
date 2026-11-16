<?php

namespace App\Livewire\Forms\Admin\Products;

use App\Enums\AttributeType;
use App\Models\Product;
use App\Support\Catalog\ProductFormData;
use App\Support\Catalog\ResolvedAttribute;
use App\Support\Slug;
use Livewire\Form;

/**
 * State and validation of the product form. The component owns the product
 * ($this->component->product); saving goes through SaveProduct. The mapping
 * and the rules live in ProductFormData (shared with the AI wizard actions).
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

    /**
     * Fiscal codes for Payme / Click receipts (plan 11b): IKPU / MXIK (17 digits) and the package code.
     */
    public string $ikpu_code = '';

    public string $package_code = '';

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
        foreach (ProductFormData::fromProduct($product, $rows) as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /**
     * An empty spec row.
     *
     * @return SpecRow
     */
    public static function blankRow(int $attributeId, bool $extra = false): array
    {
        return ProductFormData::blankRow($attributeId, $extra);
    }

    /**
     * Whether a row holds a value for its attribute type.
     *
     * @param  array<string, mixed>  $row
     */
    public static function rowHasValue(array $row, AttributeType $type): bool
    {
        return ProductFormData::rowHasValue($row, $type);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return ProductFormData::rules($this->component->product?->id);
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
            'ikpu_code' => __('admin.products.fields.ikpu_code'),
            'package_code' => __('admin.products.fields.package_code'),
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
        $product = $this->component->product ?? null;

        return ProductFormData::saveData($this->all(), $product instanceof Product ? $product : null);
    }

    /**
     * Spec input for SaveProduct.
     *
     * @return list<array<string, mixed>>
     */
    public function specInput(): array
    {
        return ProductFormData::specInput($this->specs);
    }
}
