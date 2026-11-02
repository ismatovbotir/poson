<?php

namespace App\Mcp\Support;

use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Rules\BrandSafeText;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ProductDraftRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Turns the spec list of an MCP create_product_draft call into the rows
 * SaveProduct expects (plan 13 M3c, M3-S12).
 *
 * - Attributes are named by code and only those assigned to the category
 *   (and its ancestors) are accepted; nothing is ever created (attributes
 *   and options are looked up only).
 * - Select / multiselect options are given as labels in any locale
 *   (trimmed, case-insensitive) and mapped to option ids.
 * - Errors are validation errors keyed "specs.N.field". They never repeat
 *   the given value; an unknown code or option lists at most MAX_LABELS
 *   valid ones (UntrustedText 100).
 */
final class SpecInput
{
    public const MAX_LABELS = 50;

    /**
     * Keys of one spec item.
     */
    public const KEYS = ['code', 'value', 'option', 'options', 'skipped', 'is_key'];

    public function __construct(private ProductAttributeResolver $resolver) {}

    /**
     * @param  array<int, mixed>  $specs
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function resolve(int $categoryId, array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        $errors = [];
        $allowed = [];

        foreach ($this->resolver->categoryAttributes($categoryId) as $row) {
            $allowed[$row->attribute->code] = $row->attribute;
        }

        // One query for the options of every attribute of the category.
        (new Collection(array_values($allowed)))->load('options');

        if (count($specs) > ProductDraftRules::MAX_SPECS) {
            throw ValidationException::withMessages(['specs' => 'At most '.ProductDraftRules::MAX_SPECS.' specs.']);
        }

        $rows = [];
        $seen = [];

        foreach (array_values($specs) as $index => $item) {
            $field = "specs.{$index}";

            if (! is_array($item)) {
                $errors[$field] = 'Each spec must be an object.';

                continue;
            }

            if (array_diff(array_keys($item), self::KEYS) !== []) {
                $errors[$field] = 'Unknown spec field. Allowed fields: '.implode(', ', self::KEYS).'.';

                continue;
            }

            $code = is_string($item['code'] ?? null) ? $item['code'] : '';
            $attribute = $allowed[$code] ?? null;

            if (! $attribute instanceof Attribute) {
                $errors["{$field}.code"] = 'Unknown attribute code for this category. Valid codes: '.$this->labels(array_keys($allowed)).'.';

                continue;
            }

            if (isset($seen[$attribute->id])) {
                $errors["{$field}.code"] = 'Each attribute code can be given once.';

                continue;
            }

            $seen[$attribute->id] = true;
            $rows[] = $this->row($attribute, $item, $field, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function row(Attribute $attribute, array $item, string $field, array &$errors): array
    {
        $row = [
            'attribute_id' => $attribute->id,
            'skipped' => ($item['skipped'] ?? false) === true,
            'is_key' => ($item['is_key'] ?? false) === true,
            'text' => [],
            'number' => '',
            'bool' => null,
            'option' => null,
            'options' => [],
        ];

        if ($row['skipped']) {
            return $row;
        }

        match ($attribute->type) {
            AttributeType::Text => $row['text'] = $this->text($item['value'] ?? null, $field, $errors),
            AttributeType::Number => $row['number'] = $this->number($item['value'] ?? null, $field, $errors),
            AttributeType::Boolean => $row['bool'] = $this->boolean($item['value'] ?? null, $field, $errors),
            AttributeType::Select => $row['option'] = $this->singleOption($attribute, $item['option'] ?? null, $field, $errors),
            AttributeType::Multiselect => $row['options'] = $this->manyOptions($attribute, $item['options'] ?? null, $field, $errors),
        };

        return $row;
    }

    /**
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function text(mixed $value, string $field, array &$errors): array
    {
        if ($value === null) {
            return [];
        }

        $locales = (array) config('app.supported_locales');

        if (! is_array($value) || array_diff(array_keys($value), $locales) !== []) {
            $errors["{$field}.value"] = 'A text spec needs value as an object with the keys '.implode(', ', $locales).'.';

            return [];
        }

        $text = array_fill_keys($locales, '');

        foreach ($value as $locale => $string) {
            if (! is_string($string) || mb_strlen($string) > 1000) {
                $errors["{$field}.value"] = 'Each text value must be a string of up to 1000 characters.';

                return [];
            }

            if (BrandSafeText::violates($string)) {
                $errors["{$field}.value"] = __('validation.brand_safe', ['attribute' => 'value']);

                return [];
            }

            $text[$locale] = $string;
        }

        if (trim(implode('', $text)) !== '' && trim($text['uz']) === '') {
            $errors["{$field}.value"] = 'A text spec needs the uz text.';
        }

        return $text;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function number(mixed $value, string $field, array &$errors): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_string($value)) {
            $value = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], trim($value));
        }

        if (is_bool($value) || (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value)))) {
            $errors["{$field}.value"] = 'A number spec needs value as a number.';

            return '';
        }

        if (abs((float) $value) >= 1e10) {
            $errors["{$field}.value"] = 'The number is out of range.';

            return '';
        }

        return (string) $value;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function boolean(mixed $value, string $field, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_bool($value)) {
            $errors["{$field}.value"] = 'A yes/no spec needs value as true or false.';

            return null;
        }

        return $value ? '1' : '0';
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function singleOption(Attribute $attribute, mixed $label, string $field, array &$errors): ?int
    {
        if ($label === null || $label === '') {
            return null;
        }

        if (! is_string($label)) {
            $errors["{$field}.option"] = 'A select spec needs option as one option label.';

            return null;
        }

        return $this->optionIds($attribute, [$label], "{$field}.option", $errors)[0] ?? null;
    }

    /**
     * @param  array<string, string>  $errors
     * @return list<int>
     */
    private function manyOptions(Attribute $attribute, mixed $labels, string $field, array &$errors): array
    {
        if ($labels === null) {
            return [];
        }

        if (! is_array($labels) || ! array_is_list($labels) || array_filter($labels, fn (mixed $label): bool => ! is_string($label)) !== []) {
            $errors["{$field}.options"] = 'A multiselect spec needs options as a list of option labels.';

            return [];
        }

        return $this->optionIds($attribute, $labels, "{$field}.options", $errors);
    }

    /**
     * @param  list<string>  $labels
     * @param  array<string, string>  $errors
     * @return list<int>
     */
    private function optionIds(Attribute $attribute, array $labels, string $field, array &$errors): array
    {
        $attribute->loadMissing('options');
        $byLabel = [];

        foreach ($attribute->options as $option) {
            foreach ($option->getTranslations('value') as $translated) {
                $byLabel[mb_strtolower(trim((string) $translated))] ??= $option->id;
            }
        }

        $ids = [];

        foreach ($labels as $label) {
            $id = $byLabel[mb_strtolower(trim($label))] ?? null;

            if ($id === null) {
                $valid = $attribute->options->map(fn ($option): ?string => UntrustedText::clean($option->getTranslation('value', 'uz', false) ?: $option->value, 100))->filter()->all();
                $errors[$field] = 'Unknown option for attribute '.$attribute->code.'. Valid options: '.$this->labels($valid).'.';

                return [];
            }

            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $labels
     */
    private function labels(array $labels): string
    {
        $labels = array_map(fn (string $label): string => (string) UntrustedText::clean($label, 100), array_slice(array_values($labels), 0, self::MAX_LABELS));

        return $labels === [] ? '(none)' : implode(', ', $labels);
    }
}
