<?php

namespace App\Support\Ai;

use InvalidArgumentException;

/**
 * AI-assisted fields (plan 02 §3, MVP in code). The default hints are
 * localized for the admin UI (lang ai.hints.<field key>); the manager may
 * edit the filled-in hint per request (it is not saved; the log keeps it).
 * The system prompt stays internal English. Phase 3: per-field overrides in the DB
 * (ai_prompt_overrides) would be merged in fields().
 *
 * Placeholders: {category} {brand} {name} {specs} {question}; an empty
 * value becomes "—". No CRM or personal data fields are ever registered.
 */
final class FieldRegistry
{
    public const EMPTY_VALUE = '—';

    public const TRANSLATE_HINT = 'ai.hints.translate';

    /**
     * @var array<string, FieldSpec>|null
     */
    private static ?array $fields = null;

    /**
     * @return array<string, FieldSpec>
     */
    public static function fields(): array
    {
        return self::$fields ??= self::build();
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::fields());
    }

    /**
     * @throws InvalidArgumentException for an unknown key
     */
    public static function get(string $key): FieldSpec
    {
        return self::fields()[$key] ?? throw new InvalidArgumentException("Unknown AI field [{$key}].");
    }

    /**
     * Replace {placeholders} with the given values ("—" when empty);
     * unknown placeholders are left as they are.
     *
     * @param  array<string, string|null>  $values
     */
    public static function fill(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', function (array $match) use ($values): string {
            if (! array_key_exists($match[1], $values)) {
                return $match[0];
            }

            $value = trim((string) $values[$match[1]]);

            return $value === '' ? self::EMPTY_VALUE : $value;
        }, $template);
    }

    /**
     * Language name in the admin UI language (translate hint).
     */
    public static function localizedLanguageName(string $locale): string
    {
        return __("ai.languages.{$locale}");
    }

    /**
     * English language name for prompts.
     */
    public static function languageName(string $locale): string
    {
        return match ($locale) {
            'uz' => 'Uzbek (Latin)',
            'ru' => 'Russian',
            'en' => 'English',
            default => $locale,
        };
    }

    /**
     * @return array<string, FieldSpec>
     */
    private static function build(): array
    {
        $plain = FieldSpec::PLAIN;
        $html = FieldSpec::HTML;

        $fields = [
            // Products
            new FieldSpec('product.name', 'name', 120, $plain, 'ai.hints.product.name', 'admin.catalog.name'),
            new FieldSpec('product.short_description', 'short', 300, $plain, 'ai.hints.product.short_description', 'admin.products.fields.short_description'),
            new FieldSpec('product.description', 'long', 2500, $html, 'ai.hints.product.description', 'admin.products.fields.description'),
            new FieldSpec('product.meta_title', 'seo_title', 60, $plain, 'ai.hints.product.meta_title', 'admin.catalog.meta_title'),
            new FieldSpec('product.meta_description', 'seo_description', 160, $plain, 'ai.hints.product.meta_description', 'admin.catalog.meta_description'),

            // Categories
            new FieldSpec('category.name', 'name', 60, $plain, 'ai.hints.category.name', 'admin.catalog.name'),
            new FieldSpec('category.description', 'long', 2000, $html, 'ai.hints.category.description', 'admin.catalog.description'),
            new FieldSpec('category.meta_title', 'seo_title', 60, $plain, 'ai.hints.category.meta_title', 'admin.catalog.meta_title'),
            new FieldSpec('category.meta_description', 'seo_description', 160, $plain, 'ai.hints.category.meta_description', 'admin.catalog.meta_description'),

            // Brands
            new FieldSpec('brand.description', 'long', 1200, $html, 'ai.hints.brand.description', 'admin.catalog.description'),

            // FAQ items (category, product or general FAQ)
            new FieldSpec('faq.question', 'question', 150, $plain, 'ai.hints.faq.question', 'admin.faq.fields.question'),
            new FieldSpec('faq.answer', 'answer', 600, $plain, 'ai.hints.faq.answer', 'admin.faq.fields.answer'),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
    }
}
