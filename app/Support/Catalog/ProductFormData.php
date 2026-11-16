<?php

namespace App\Support\Catalog;

use App\Enums\AttributeType;
use App\Enums\ProductBadge;
use App\Enums\ProductPricingUnit;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Content\ContentLimits;
use App\Support\Money;
use App\Support\Orders\CheckoutSettings;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The product form's data, without Livewire (plan 32 §14 plan change 6):
 * product → form state, the form's validation rules, form state →
 * SaveProduct input. ProductForm and the AI wizard actions share it, so an
 * action never needs a Livewire form object.
 *
 * State keys are the ProductForm properties (strings as the inputs send
 * them; prices in whole soʻm, specs as SpecRow).
 *
 * @phpstan-type SpecRow array{attribute_id: int, extra: bool, skipped: bool, is_key: bool, text: array<string, string>, number: string, bool: string, option: string, options: list<int>}
 */
final class ProductFormData
{
    /**
     * @param  list<ResolvedAttribute>  $rows  ProductAttributeResolver::forProduct()
     * @return array<string, mixed>
     */
    public static function fromProduct(Product $product, array $rows): array
    {
        $values = $product->attributeValues()->get()->groupBy('attribute_id');

        return [
            'name' => self::locales($product->getTranslations('name')),
            'short_description' => self::locales($product->getTranslations('short_description')),
            'description' => self::locales($product->getTranslations('description')),
            'meta_title' => self::locales($product->getTranslations('meta_title')),
            'meta_description' => self::locales($product->getTranslations('meta_description')),
            'type' => $product->type->value,
            'category_id' => (string) $product->category_id,
            'brand_id' => (string) ($product->brand_id ?? ''),
            'sku' => $product->sku,
            'model' => (string) $product->model,
            'slug' => $product->slug,
            'price' => Money::tiyinToSums($product->price),
            'price_dealer' => Money::tiyinToSums($product->price_dealer),
            'price_wholesale' => Money::tiyinToSums($product->price_wholesale),
            'pricing_unit' => $product->pricing_unit->value,
            'roll_type_ids' => $product->rollTypes()->pluck('roll_types.id')->map(fn ($id): int => (int) $id)->values()->all(),
            'unit' => $product->unit->value,
            'min_qty' => (string) $product->min_qty,
            'sale_mode' => $product->sale_mode->value,
            'stock_status' => $product->stock_status->value,
            'ikpu_code' => (string) $product->ikpu_code,
            'package_code' => (string) $product->package_code,
            'warranty_months' => (string) ($product->warranty_months ?? ''),
            'badge' => (string) $product->badge?->value,
            'is_active' => $product->is_active,
            'is_featured' => $product->is_featured,
            'specs' => array_map(
                fn (ResolvedAttribute $row): array => self::rowFromValues($row, $values->get($row->attribute->id, collect())),
                $rows,
            ),
        ];
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
     * The product form's validation rules (keys without the "form." prefix).
     *
     * @return array<string, mixed>
     */
    public static function rules(?int $ignoreProductId = null): array
    {
        $price = ['nullable', 'string', 'max:24', function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && Money::sumsToTiyin((string) $value) === null) {
                $fail(__('admin.products.errors.price_invalid'));
            }
        }];

        return [
            'name.uz' => ['required', 'string', 'max:'.ContentLimits::SHORT_TEXT_MAX],
            'name.ru' => ['nullable', 'string', 'max:'.ContentLimits::SHORT_TEXT_MAX],
            'name.en' => ['nullable', 'string', 'max:'.ContentLimits::SHORT_TEXT_MAX],
            'short_description.*' => ['nullable', 'string', 'max:500'],
            'description.*' => ['nullable', 'string', 'max:'.Product::DESCRIPTION_MAX],
            'meta_title.*' => ['nullable', 'string', 'max:'.ContentLimits::SHORT_TEXT_MAX],
            'meta_description.*' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::enum(ProductType::class)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9._\/+ -]*$/', Rule::unique('products', 'sku')->ignore($ignoreProductId)],
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
            'ikpu_code' => ['nullable', 'regex:'.CheckoutSettings::IKPU],
            'package_code' => ['nullable', 'regex:'.CheckoutSettings::PACKAGE_CODE],
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
     * Input for SaveProduct (prices converted from whole soʻm to tiyin; an
     * unchanged price input keeps the stored tiyin of $product).
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function saveData(array $state, ?Product $product = null): array
    {
        $pricingUnit = (string) $state['pricing_unit'];

        return [
            'type' => ProductType::from((string) $state['type']),
            'category_id' => (int) $state['category_id'],
            'brand_id' => filled($state['brand_id']) ? (int) $state['brand_id'] : null,
            'sku' => (string) $state['sku'],
            'model' => filled($state['model']) ? (string) $state['model'] : null,
            'name' => $state['name'],
            'short_description' => $state['short_description'],
            'description' => $state['description'],
            'meta_title' => $state['meta_title'],
            'meta_description' => $state['meta_description'],
            'price' => self::priceInTiyin($state, 'price', $product),
            'price_dealer' => self::priceInTiyin($state, 'price_dealer', $product),
            'price_wholesale' => self::priceInTiyin($state, 'price_wholesale', $product),
            'pricing_unit' => ProductPricingUnit::from($pricingUnit),
            'roll_type_ids' => array_values(array_map('intval', (array) $state['roll_type_ids'])),
            'unit' => $pricingUnit === ProductPricingUnit::Label->value ? ProductUnit::Roll : ProductUnit::from((string) $state['unit']),
            'min_qty' => (int) $state['min_qty'],
            'sale_mode' => SaleMode::from((string) $state['sale_mode']),
            'stock_status' => StockStatus::from((string) $state['stock_status']),
            'ikpu_code' => trim((string) $state['ikpu_code']) !== '' ? trim((string) $state['ikpu_code']) : null,
            'package_code' => trim((string) $state['package_code']) !== '' ? trim((string) $state['package_code']) : null,
            'warranty_months' => filled($state['warranty_months']) ? (int) $state['warranty_months'] : null,
            'badge' => filled($state['badge']) ? ProductBadge::from((string) $state['badge']) : null,
            'is_active' => (bool) $state['is_active'],
            'is_featured' => (bool) $state['is_featured'],
        ];
    }

    /**
     * Spec input for SaveProduct.
     *
     * @param  list<array<string, mixed>>  $specs
     * @return list<array<string, mixed>>
     */
    public static function specInput(array $specs): array
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
        ], $specs));
    }

    /**
     * A price input in tiyin. An unchanged input keeps the stored tiyin, so a
     * price with tiyin below one soʻm (e.g. imported) survives saving the form,
     * which only shows whole soʻm.
     *
     * @param  array<string, mixed>  $state
     */
    private static function priceInTiyin(array $state, string $field, ?Product $product): ?int
    {
        $typed = Money::sumsToTiyin((string) $state[$field]);

        if ($product instanceof Product) {
            $stored = $product->getAttribute($field);

            if ($typed === Money::sumsToTiyin(Money::tiyinToSums($stored))) {
                return $stored;
            }
        }

        return $typed;
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     * @return SpecRow
     */
    private static function rowFromValues(ResolvedAttribute $resolved, Collection $values): array
    {
        $row = self::blankRow($resolved->attribute->id, $resolved->isExtra());
        $row['skipped'] = $resolved->skipped;
        $row['is_key'] = $values->contains(fn (ProductAttributeValue $value): bool => $value->is_key);
        $first = $values->first();

        if ($first === null) {
            return $row;
        }

        match ($resolved->attribute->type) {
            AttributeType::Text => $row['text'] = self::locales($first->getTranslations('value_text')),
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
    private static function locales(array $values): array
    {
        $result = [];

        foreach (config('app.supported_locales') as $locale) {
            $result[$locale] = (string) ($values[$locale] ?? '');
        }

        return $result;
    }
}
