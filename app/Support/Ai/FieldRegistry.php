<?php

namespace App\Support\Ai;

use App\Models\Banner;
use InvalidArgumentException;

/**
 * AI-assisted fields (plan 02 §3, plan 12). The default hints are localized
 * for the admin UI (lang ai.hints.<field key>); an admin may override one
 * per field for every locale in Settings → AI prompts (PromptOverrides,
 * read in FieldSpec::template(), never memoized here), and the manager may
 * still edit the filled-in hint per request (not saved; the log keeps it).
 * The system prompt, output rules and BrandGuard stay in code.
 *
 * Each field declares its allowed placeholders (a subset of {name}
 * {category} {brand} {question}); an empty value becomes "—". No CRM or
 * personal data fields are ever registered. A removed key fails requests
 * still queued for it with "field_retired" (ProcessAiRequest).
 */
final class FieldRegistry
{
    public const EMPTY_VALUE = '—';

    public const TRANSLATE_HINT = 'ai.hints.translate';

    /**
     * Field groups in screen order (Settings → AI prompts).
     */
    public const GROUPS = ['products', 'categories', 'brands', 'pages', 'news', 'articles', 'faq', 'banners'];

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

    /**
     * Registered fields by group, in GROUPS order (empty groups left out).
     *
     * @return array<string, list<FieldSpec>>
     */
    public static function groups(): array
    {
        $groups = array_fill_keys(self::GROUPS, []);

        foreach (self::fields() as $field) {
            $groups[$field->group][] = $field;
        }

        return array_filter($groups);
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

        $product = ['name', 'brand', 'category'];
        $category = ['name', 'category'];
        $name = ['name'];

        $fields = [
            // Products
            new FieldSpec('product.short_description', 'short', 300, $plain, 'ai.hints.product.short_description', 'admin.products.fields.short_description', 'products', $product),
            new FieldSpec('product.description', 'long', 2500, $html, 'ai.hints.product.description', 'admin.products.fields.description', 'products', $product),
            new FieldSpec('product.meta_title', 'seo_title', 60, $plain, 'ai.hints.product.meta_title', 'admin.catalog.meta_title', 'products', $product),
            new FieldSpec('product.meta_description', 'seo_description', 160, $plain, 'ai.hints.product.meta_description', 'admin.catalog.meta_description', 'products', $product),
            new FieldSpec('product.image_alt', 'alt', 125, $plain, 'ai.hints.product.image_alt', 'admin.image_upload.alt', 'products', $product),

            // Categories
            new FieldSpec('category.description', 'long', 2000, $html, 'ai.hints.category.description', 'admin.catalog.description', 'categories', $category),
            new FieldSpec('category.meta_title', 'seo_title', 60, $plain, 'ai.hints.category.meta_title', 'admin.catalog.meta_title', 'categories', $category),
            new FieldSpec('category.meta_description', 'seo_description', 160, $plain, 'ai.hints.category.meta_description', 'admin.catalog.meta_description', 'categories', $category),

            // Brands
            new FieldSpec('brand.description', 'long', 1200, $html, 'ai.hints.brand.description', 'admin.catalog.description', 'brands', $name),
            new FieldSpec('brand.meta_title', 'seo_title', 60, $plain, 'ai.hints.brand.meta_title', 'admin.catalog.meta_title', 'brands', $name),
            new FieldSpec('brand.meta_description', 'seo_description', 160, $plain, 'ai.hints.brand.meta_description', 'admin.catalog.meta_description', 'brands', $name),

            // Static pages (plan 07). The body of legal pages is never offered (Pages\Form).
            new FieldSpec('page.body', 'long', 3000, $html, 'ai.hints.page.body', 'admin.pages.fields.body', 'pages', $name),
            new FieldSpec('page.meta_title', 'seo_title', 60, $plain, 'ai.hints.page.meta_title', 'admin.catalog.meta_title', 'pages', $name),
            new FieldSpec('page.meta_description', 'seo_description', 160, $plain, 'ai.hints.page.meta_description', 'admin.catalog.meta_description', 'pages', $name),

            // News posts of kind "news" (plan 07 7b)
            new FieldSpec('news.excerpt', 'short', 300, $plain, 'ai.hints.news.excerpt', 'admin.news.fields.excerpt', 'news', $name),
            new FieldSpec('news.body', 'long', 3000, $html, 'ai.hints.news.body', 'admin.news.fields.body', 'news', $name),
            new FieldSpec('news.meta_title', 'seo_title', 60, $plain, 'ai.hints.news.meta_title', 'admin.catalog.meta_title', 'news', $name),
            new FieldSpec('news.meta_description', 'seo_description', 160, $plain, 'ai.hints.news.meta_description', 'admin.catalog.meta_description', 'news', $name),

            // News posts of kind "article" (knowledge articles, plan 12)
            new FieldSpec('article.excerpt', 'short', 300, $plain, 'ai.hints.article.excerpt', 'admin.news.fields.excerpt', 'articles', $name),
            new FieldSpec('article.body', 'long', 4000, $html, 'ai.hints.article.body', 'admin.news.fields.body', 'articles', $name),
            new FieldSpec('article.meta_title', 'seo_title', 60, $plain, 'ai.hints.article.meta_title', 'admin.catalog.meta_title', 'articles', $name),
            new FieldSpec('article.meta_description', 'seo_description', 160, $plain, 'ai.hints.article.meta_description', 'admin.catalog.meta_description', 'articles', $name),

            // FAQ items (category, product, page or general FAQ)
            new FieldSpec('faq.answer', 'answer', 600, $plain, 'ai.hints.faq.answer', 'admin.faq.fields.answer', 'faq', ['name', 'category', 'question']),

            // Banners (plan 12): home slider or category banner
            new FieldSpec('banner.title', 'headline', Banner::TITLE_MAX, $plain, 'ai.hints.banner.title', 'admin.banners.fields.title', 'banners', $category),
            new FieldSpec('banner.text', 'short', Banner::TEXT_MAX, $plain, 'ai.hints.banner.text', 'admin.banners.fields.text', 'banners', $category),
        ];

        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->key] = $field;
        }

        return $keyed;
    }
}
