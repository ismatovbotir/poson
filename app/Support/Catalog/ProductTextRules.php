<?php

namespace App\Support\Catalog;

use App\Models\Product;
use App\Rules\BrandSafeText;
use App\Support\Content\ContentLimits;
use App\Support\HtmlSanitizer;
use App\Support\Site\StructuredData;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * The one rule set for the public texts of a product (plan T6): name, short
 * description, description, meta title / description, model and the text
 * specs. Used by the admin ProductForm, the MCP ProductDraftRules and
 * enforced by SaveProduct itself, so every caller (form, MCP, wizard) is covered.
 *
 * - Translatable props accept only the supported locale keys.
 * - Texts are checked as given and again without tags (so "ски<b>дка</b>"
 *   does not slip through); a description is sanitized first and checked as
 *   plainText() and as entity-decoded strip_tags() without inserted spaces.
 * - POS/service wording ("point of sale", "after-sales service", "sales
 *   receipt") is allowed per sentence (BrandSafeText::PRODUCT context).
 * - Messages never repeat the input.
 */
final class ProductTextRules
{
    public const SHORT_MAX = 500;

    public const SPEC_MAX = 1000;

    /**
     * Translatable props of a product that carry public text.
     *
     * @var list<string>
     */
    public const TRANSLATABLE = ['name', 'short_description', 'description', 'meta_title', 'meta_description'];

    private const LABELS = [
        'name' => 'admin.catalog.name',
        'short_description' => 'admin.products.fields.short_description',
        'description' => 'admin.catalog.description',
        'meta_title' => 'admin.catalog.meta_title',
        'meta_description' => 'admin.catalog.meta_description',
    ];

    /**
     * Validation rules (form and MCP draft) for the public product texts.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $locales = 'array:'.implode(',', config('app.supported_locales'));
        $rules = [
            'model' => ['nullable', 'string', 'max:255', self::textRule()],
            'name' => ['required', $locales],
            'short_description' => ['nullable', $locales],
            'description' => ['nullable', $locales],
            'meta_title' => ['nullable', $locales],
            'meta_description' => ['nullable', $locales],
        ];

        foreach (config('app.supported_locales') as $locale) {
            $rules["name.{$locale}"] = [$locale === 'uz' ? 'required' : 'nullable', 'string', 'max:'.ContentLimits::SHORT_TEXT_MAX, self::textRule()];
            $rules["short_description.{$locale}"] = ['nullable', 'string', 'max:'.self::SHORT_MAX, self::textRule()];
            $rules["description.{$locale}"] = ['nullable', 'string', 'max:'.Product::DESCRIPTION_MAX, self::descriptionRule()];
            $rules["meta_title.{$locale}"] = ['nullable', 'string', 'max:'.ContentLimits::SHORT_TEXT_MAX, self::textRule()];
            $rules["meta_description.{$locale}"] = ['nullable', 'string', 'max:'.self::SHORT_MAX, self::textRule()];
        }

        return $rules;
    }

    public static function textRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && self::violatesPlain($value)) {
                $fail('validation.brand_safe')->translate();
            }
        };
    }

    public static function descriptionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && self::violatesDescription($value)) {
                $fail('validation.brand_safe')->translate();
            }
        };
    }

    /**
     * A plain text (name, meta, model, spec text), as given and without tags.
     */
    public static function violatesPlain(string $text): bool
    {
        if (BrandSafeText::violates($text, BrandSafeText::PRODUCT)) {
            return true;
        }

        return BrandSafeText::violates(self::stripped($text), BrandSafeText::PRODUCT);
    }

    /**
     * A description: sanitized first, then checked as plainText() and as
     * decoded strip_tags() without the inserted spaces.
     */
    public static function violatesDescription(string $html): bool
    {
        $clean = HtmlSanitizer::clean($html);

        return BrandSafeText::violates(StructuredData::plainText($clean), BrandSafeText::PRODUCT)
            || BrandSafeText::violates(self::stripped($clean), BrandSafeText::PRODUCT);
    }

    /**
     * Enforced by SaveProduct. Errors are keyed like the form ("name.uz",
     * "specs.2.text.ru") and never contain the input.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $specs
     *
     * @throws ValidationException
     */
    public static function assert(array $data, array $specs): void
    {
        $errors = [];
        $locales = (array) config('app.supported_locales');
        $message = fn (string $label): string => __('validation.brand_safe', ['attribute' => $label]);

        foreach (self::TRANSLATABLE as $field) {
            foreach ((array) ($data[$field] ?? []) as $locale => $value) {
                if (! in_array($locale, $locales, true)) {
                    // An unknown key is input: the message names only the field.
                    $errors["{$field}.{$locale}"] = __('validation.in', ['attribute' => __(self::LABELS[$field])]);

                    continue;
                }

                $label = __(self::LABELS[$field]).' ('.strtoupper((string) $locale).')';

                if (is_string($value) && ($field === 'description' ? self::violatesDescription($value) : self::violatesPlain($value))) {
                    $errors["{$field}.{$locale}"] = $message($label);
                }
            }
        }

        if (is_string($data['model'] ?? null) && self::violatesPlain($data['model'])) {
            $errors['model'] = $message(__('admin.products.fields.model'));
        }

        foreach (array_values($specs) as $index => $spec) {
            foreach ((array) ($spec['text'] ?? []) as $locale => $value) {
                if (! in_array($locale, $locales, true)) {
                    $errors["specs.{$index}.text.{$locale}"] = __('validation.in', ['attribute' => __('admin.products.specs.value')]);
                } elseif (is_string($value) && self::violatesPlain($value)) {
                    $errors["specs.{$index}.text.{$locale}"] = $message(__('admin.products.specs.value').' ('.strtoupper((string) $locale).')');
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Text without tags and with entities decoded, no spaces inserted.
     */
    private static function stripped(string $text): string
    {
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
    }
}
