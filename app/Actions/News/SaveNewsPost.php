<?php

namespace App\Actions\News;

use App\Enums\NewsStatus;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Support\HtmlSanitizer;
use App\Support\Site\StructuredData;
use App\Support\Slug;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a news post with its related products and categories
 * (the cover image goes through UpdateCatalogImage).
 *
 * Re-checks what must never be stored whatever the caller: the uz title, the
 * brand rule (BrandSafeText) on every public text, sanitized body HTML, a
 * unique Latin slug (from the uz title when left empty) and, for a published
 * post, at least one locale with both title and body, and a publication
 * date between 2000 and 2099. A published post without a date is published
 * now; a future date schedules it. A slug taken concurrently (unique index)
 * becomes a slug validation error.
 *
 * content_updated_at is set on create and whenever the title, excerpt or
 * body actually change. The author is set once, on create.
 */
class SaveNewsPost
{
    /**
     * Accepted publication dates (exclusive bounds, UTC).
     */
    public const DATE_MIN = '2000-01-01';

    public const DATE_MAX = '2100-01-01';

    /**
     * Public texts checked against the brand rule.
     */
    private const PUBLIC_FIELDS = ['title', 'excerpt', 'meta_title', 'meta_description'];

    /**
     * @param  array{
     *     title: array<string, string|null>,
     *     excerpt: array<string, string|null>,
     *     body: array<string, string|null>,
     *     meta_title: array<string, string|null>,
     *     meta_description: array<string, string|null>,
     *     slug: string|null,
     *     status: string,
     *     published_at: CarbonInterface|null,
     *     product_ids: list<int>,
     *     category_ids: list<int>
     * }  $data
     *
     * @throws ValidationException
     */
    public function handle(?NewsPost $post, array $data, ?User $author = null): NewsPost
    {
        $post ??= new NewsPost;
        $body = array_map(HtmlSanitizer::cleanOrNull(...), $data['body'] ?? []);
        $status = NewsStatus::tryFrom((string) ($data['status'] ?? '')) ?? NewsStatus::Draft;

        $this->check($data, $body, $status);

        return DB::transaction(function () use ($post, $data, $body, $status, $author): NewsPost {
            $contentBefore = $this->content($post);

            $post->storeTranslations('title', $data['title']);
            $post->storeTranslations('excerpt', $data['excerpt'] ?? []);
            $post->storeTranslations('body', $body);

            if (! $post->exists || $this->content($post) !== $contentBefore) {
                $post->content_updated_at = now();
            }

            $post->storeTranslations('meta_title', $data['meta_title'] ?? []);
            $post->storeTranslations('meta_description', $data['meta_description'] ?? []);

            $publishedAt = $data['published_at'] ?? null;

            if ($status === NewsStatus::Published && $publishedAt === null) {
                $publishedAt = now();
            }

            $post->fill([
                'slug' => $this->slug($post, $data),
                'status' => $status,
                'published_at' => $publishedAt,
            ]);

            if (! $post->exists && $author !== null) {
                $post->author_id = $author->id;
            }

            try {
                $post->save();
            } catch (UniqueConstraintViolationException) {
                // Another save took the slug between the check and the insert.
                throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => __('admin.catalog.slug')])]);
            }

            $post->products()->sync($this->existing(Product::class, $data['product_ids'] ?? []));
            $post->categories()->sync($this->existing(Category::class, $data['category_ids'] ?? []));

            return $post;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $body
     *
     * @throws ValidationException
     */
    private function check(array $data, array $body, NewsStatus $status): void
    {
        $errors = [];
        $locales = config('app.supported_locales');

        if (trim((string) ($data['title']['uz'] ?? '')) === '') {
            $errors['title.uz'] = __('validation.required', ['attribute' => __('admin.news.fields.title').' (UZ)']);
        }

        $texts = [];

        foreach (self::PUBLIC_FIELDS as $field) {
            $texts[$field] = $data[$field] ?? [];
        }

        $texts['body'] = array_map(fn (?string $html): string => StructuredData::plainText((string) $html), $body);

        foreach ($texts as $field => $values) {
            foreach ($locales as $locale) {
                $text = (string) ($values[$locale] ?? '');

                if (! isset($errors["{$field}.{$locale}"]) && $text !== '' && BrandSafeText::violates($text)) {
                    $errors["{$field}.{$locale}"] = __('validation.brand_safe', ['attribute' => __("admin.news.fields.{$field}").' ('.strtoupper($locale).')']);
                }
            }
        }

        foreach ($body as $locale => $html) {
            if (mb_strlen((string) $html) > NewsPost::BODY_MAX) {
                $errors["body.{$locale}"] = __('validation.max.string', ['attribute' => __('admin.news.fields.body').' ('.strtoupper((string) $locale).')', 'max' => NewsPost::BODY_MAX]);
            }
        }

        $publishedAt = $data['published_at'] ?? null;

        if ($publishedAt instanceof CarbonInterface && ($publishedAt->lessThanOrEqualTo(Carbon::parse(self::DATE_MIN)) || $publishedAt->greaterThanOrEqualTo(Carbon::parse(self::DATE_MAX)))) {
            $errors['published_at'] = __('admin.news.errors.published_at_range');
        }

        if ($status === NewsStatus::Published && ! isset($errors['title.uz'])) {
            $translated = array_filter($locales, fn (string $locale): bool => trim((string) ($data['title'][$locale] ?? '')) !== ''
                && StructuredData::plainText((string) ($body[$locale] ?? '')) !== '');

            if ($translated === []) {
                $errors['body.uz'] = __('admin.news.errors.no_translation');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function slug(NewsPost $post, array $data): string
    {
        $ignoreId = $post->exists ? $post->id : null;
        $given = Slug::make($data['slug'] ?? '');

        if ($given !== '') {
            if (Slug::taken(NewsPost::class, $given, $ignoreId)) {
                throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => __('admin.catalog.slug')])]);
            }

            return $given;
        }

        return Slug::uniqueFor(NewsPost::class, Slug::make($data['title']['uz'] ?? '') ?: 'news', $ignoreId);
    }

    /**
     * The given IDs that exist (trashed products are never linked anew).
     *
     * @param  class-string<Product|Category>  $model
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function existing(string $model, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        return $model::query()->whereKey($ids)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Title, excerpt and body as stored (or about to be), locale order
     * normalized: MySQL reorders JSON keys, so raw strings can't be compared.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function content(NewsPost $post): array
    {
        $content = [];

        foreach (['title', 'excerpt', 'body'] as $key) {
            $raw = $post->getAttributes()[$key] ?? null;
            $value = is_string($raw) ? json_decode($raw, true) : $raw;

            if (is_array($value)) {
                ksort($value);
            }

            $content[$key] = is_array($value) && $value !== [] ? $value : null;
        }

        return $content;
    }
}
