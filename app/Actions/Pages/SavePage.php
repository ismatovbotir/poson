<?php

namespace App\Actions\Pages;

use App\Enums\PageTemplate;
use App\Models\Page;
use App\Rules\BrandSafeText;
use App\Support\HtmlSanitizer;
use App\Support\Site\StructuredData;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a static page (fields only; the cover image goes through
 * UpdateCatalogImage, the FAQ through App\Actions\Faq\*).
 *
 * Re-checks what must never be stored whatever the caller: the uz title, the
 * brand rule (BrandSafeText) on every public text, sanitized body HTML, a
 * unique Latin slug generated once from the title (never changed after that,
 * incoming slugs are ignored) that no custom page may take from the system
 * list, and the fixed slug and template of system pages (only texts, SEO,
 * sort, active and the review flag change there). A generated slug taken
 * concurrently is regenerated once (Slug::saveGenerated).
 *
 * content_updated_at is set on create and whenever the title or body
 * actually change (not on SEO, sort, active or review-flag edits).
 */
class SavePage
{
    /**
     * @param  array{
     *     title: array<string, string|null>,
     *     body: array<string, string|null>,
     *     meta_title: array<string, string|null>,
     *     meta_description: array<string, string|null>,
     *     template: string|null,
     *     sort: int|null,
     *     is_active: bool,
     *     needs_review: bool
     * }  $data
     *
     * @throws ValidationException
     */
    public function handle(?Page $page, array $data): Page
    {
        $page ??= new Page;
        $body = array_map(HtmlSanitizer::cleanOrNull(...), $data['body'] ?? []);

        $this->check($data, $body);

        return DB::transaction(function () use ($page, $data, $body): Page {
            $contentBefore = $this->content($page);

            $page->storeTranslations('title', $data['title']);
            $page->storeTranslations('body', $body);

            if (! $page->exists || $this->content($page) !== $contentBefore) {
                $page->content_updated_at = now();
            }

            $page->storeTranslations('meta_title', $data['meta_title'] ?? []);
            $page->storeTranslations('meta_description', $data['meta_description'] ?? []);

            $generated = ! $page->is_system && ! ($page->exists && (string) $page->getOriginal('slug') !== '');

            $page->fill([
                'slug' => $page->is_system ? $page->slug : $this->slug($page, $data),
                'template' => $page->is_system ? $page->template : $this->template($data['template'] ?? null),
                'sort' => $data['sort'] ?? ($page->exists ? $page->sort : (int) Page::query()->max('sort') + 1),
                'is_active' => (bool) $data['is_active'],
                'needs_review' => (bool) $data['needs_review'],
            ]);

            $page->is_system = (bool) $page->is_system;

            // A page created concurrently with the same title: next free slug, once.
            Slug::saveGenerated($page, $generated, fn (string $clashed): string => $this->slug($page, $data, $clashed));

            return $page;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $body
     *
     * @throws ValidationException
     */
    private function check(array $data, array $body): void
    {
        $errors = [];

        if (trim((string) ($data['title']['uz'] ?? '')) === '') {
            $errors['title.uz'] = __('validation.required', ['attribute' => __('admin.pages.fields.title').' (UZ)']);
        }

        $texts = [
            'title' => $data['title'] ?? [],
            'meta_title' => $data['meta_title'] ?? [],
            'meta_description' => $data['meta_description'] ?? [],
            'body' => array_map(fn (?string $html): string => StructuredData::plainText((string) $html), $body),
        ];

        foreach ($texts as $field => $values) {
            foreach (config('app.supported_locales') as $locale) {
                $text = (string) ($values[$locale] ?? '');

                if (! isset($errors["{$field}.{$locale}"]) && $text !== '' && BrandSafeText::violates($text)) {
                    $errors["{$field}.{$locale}"] = __('validation.brand_safe', ['attribute' => __("admin.pages.fields.{$field}").' ('.strtoupper($locale).')']);
                }
            }
        }

        foreach ($body as $locale => $html) {
            if (mb_strlen((string) $html) > Page::BODY_MAX) {
                $errors["body.{$locale}"] = __('validation.max.string', ['attribute' => __('admin.pages.fields.body').' ('.strtoupper((string) $locale).')', 'max' => Page::BODY_MAX]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The stored slug once there is one (never changed, whatever the input);
     * otherwise a unique slug from the en title, else uz, that is not on the
     * system list (nor $except, a slug that just clashed). Incoming slugs are
     * ignored.
     *
     * @param  array<string, mixed>  $data
     */
    private function slug(Page $page, array $data, ?string $except = null): string
    {
        $stored = (string) $page->getOriginal('slug');

        if ($page->exists && $stored !== '') {
            return $stored;
        }

        $ignoreId = $page->exists ? $page->id : null;
        $source = filled($data['title']['en'] ?? null) ? $data['title']['en'] : ($data['title']['uz'] ?? '');

        return Slug::unique(
            Slug::make($source) ?: 'page',
            fn (string $slug): bool => $slug === $except || Page::isSystemSlug($slug) || Slug::taken(Page::class, $slug, $ignoreId),
        );
    }

    /**
     * Title and body as stored (or about to be), locale order normalized:
     * MySQL reorders JSON keys, so raw strings can't be compared.
     *
     * @return array{title: array<string, mixed>|null, body: array<string, mixed>|null}
     */
    private function content(Page $page): array
    {
        $content = [];

        foreach (['title', 'body'] as $key) {
            $raw = $page->getAttributes()[$key] ?? null;
            $value = is_string($raw) ? json_decode($raw, true) : $raw;

            if (is_array($value)) {
                ksort($value);
            }

            $content[$key] = is_array($value) && $value !== [] ? $value : null;
        }

        return $content;
    }

    private function template(?string $value): PageTemplate
    {
        return PageTemplate::tryFrom((string) $value) ?? PageTemplate::Default;
    }
}
