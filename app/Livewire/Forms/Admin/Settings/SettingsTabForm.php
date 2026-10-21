<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Rules\BrandSafeText;
use App\Support\CompanySettings;
use Livewire\Form;

/**
 * Base of the Settings → Company tab forms: fill from the current settings
 * (raw values, so an invalid stored value can be seen and fixed), normalize
 * the input, validate, and return the key => value map that
 * UpdateCompanySettings saves. Messages never echo the input.
 */
abstract class SettingsTabForm extends Form
{
    /**
     * Load the current values.
     */
    abstract public function fillFrom(CompanySettings $settings): void;

    /**
     * Validated values to save, key => value.
     *
     * @return array<string, mixed>
     */
    abstract public function values(): array;

    /**
     * Clean the input before validation (trim, strip spaces from numbers…).
     */
    public function normalize(): void {}

    /**
     * @return list<string>
     */
    protected static function locales(): array
    {
        return config('app.supported_locales');
    }

    /**
     * {uz, ru, en} strings from a stored value.
     *
     * @return array<string, string>
     */
    protected static function translation(mixed $value): array
    {
        $value = is_array($value) ? $value : ['uz' => is_scalar($value) ? (string) $value : ''];

        return collect(static::locales())
            ->mapWithKeys(fn (string $locale): array => [$locale => is_scalar($value[$locale] ?? null) ? (string) $value[$locale] : ''])
            ->all();
    }

    /**
     * Trimmed {uz, ru, en} strings.
     *
     * @param  array<string, mixed>  $value
     * @return array<string, string>
     */
    protected static function trimTranslation(array $value): array
    {
        return collect(static::locales())
            ->mapWithKeys(fn (string $locale): array => [$locale => trim((string) ($value[$locale] ?? ''))])
            ->all();
    }

    /**
     * Rules for a translatable text field: uz required (or optional), all
     * locales length-limited and brand-safe.
     *
     * @return array<string, list<mixed>>
     */
    protected static function translationRules(string $field, int $max, bool $required = true): array
    {
        $rules = [];

        foreach (static::locales() as $locale) {
            $rules["{$field}.{$locale}"] = [
                $required && $locale === 'uz' ? 'required' : 'nullable',
                'string',
                "max:{$max}",
                new BrandSafeText,
            ];
        }

        return $rules;
    }

    /**
     * Attribute names "Label (UZ)" for a translatable field.
     *
     * @return array<string, string>
     */
    protected static function translationAttributes(string $field, string $label): array
    {
        $attributes = [];

        foreach (static::locales() as $locale) {
            $attributes["{$field}.{$locale}"] = $label.' ('.strtoupper($locale).')';
        }

        return $attributes;
    }
}
