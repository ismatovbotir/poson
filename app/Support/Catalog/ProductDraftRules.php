<?php

namespace App\Support\Catalog;

use App\Actions\Catalog\SaveProduct;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Rules\BrandSafeText;
use App\Support\HtmlSanitizer;
use App\Support\Site\StructuredData;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validation rules of a product draft created through MCP (create_product_draft,
 * plan 13 M3c, M3-S11): lengths and enums as in the admin ProductForm, but
 * only the fields a draft may carry (no prices, stock, badge, flags, fiscal
 * codes, roll types, images). The brand rule applies to every public text in
 * every locale: name, short description, the plain text of the sanitized
 * description, meta texts and the model (spec texts: SpecInput).
 */
final class ProductDraftRules
{
    public const MAX_SPECS = 60;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $locales = 'array:'.implode(',', config('app.supported_locales'));
        $brandSafe = new BrandSafeText;

        return [
            'type' => ['required', 'string', Rule::enum(ProductType::class)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'sku' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! preg_match(SaveProduct::SKU_PATTERN, mb_strtoupper(trim($value)))) {
                    $fail('validation.regex')->translate();
                }
            }],
            'model' => ['nullable', 'string', 'max:255', $brandSafe],
            'name' => ['required', $locales],
            'name.uz' => ['required', 'string', 'max:255', $brandSafe],
            'name.ru' => ['nullable', 'string', 'max:255', $brandSafe],
            'name.en' => ['nullable', 'string', 'max:255', $brandSafe],
            'short_description' => ['nullable', $locales],
            'short_description.*' => ['nullable', 'string', 'max:500', $brandSafe],
            'description' => ['nullable', $locales],
            'description.*' => ['nullable', 'string', 'max:50000', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && BrandSafeText::violates(StructuredData::plainText(HtmlSanitizer::clean($value)))) {
                    $fail('validation.brand_safe')->translate();
                }
            }],
            'meta_title' => ['nullable', $locales],
            'meta_title.*' => ['nullable', 'string', 'max:255', $brandSafe],
            'meta_description' => ['nullable', $locales],
            'meta_description.*' => ['nullable', 'string', 'max:500', $brandSafe],
            'unit' => ['nullable', 'string', Rule::enum(ProductUnit::class)->except(ProductUnit::Roll)],
            'min_qty' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'specs' => ['nullable', 'array', 'max:'.self::MAX_SPECS],
            'specs.*' => ['array'],
            'specs.*.code' => ['required', 'string', 'max:64'],
            'specs.*.skipped' => ['nullable', 'boolean'],
            'specs.*.is_key' => ['nullable', 'boolean'],
            'specs.*.option' => ['nullable', 'string', 'max:255'],
            'specs.*.options' => ['nullable', 'array', 'max:50'],
            'specs.*.options.*' => ['string', 'max:255'],
        ];
    }
}
