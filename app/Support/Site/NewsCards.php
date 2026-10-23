<?php

namespace App\Support\Site;

use App\Data\Site\NewsCard;
use App\Models\NewsPost;
use Illuminate\Support\Str;

/**
 * Maps visible news posts to App\Data\Site\NewsCard in the current locale.
 * Query with ->with('media') (strict models forbid lazy loading). The
 * excerpt falls back to the start of the body as plain text, the cover alt
 * to the title (never to the alt of another locale).
 */
class NewsCards
{
    public const EXCERPT_LENGTH = 180;

    /**
     * Cards on the home page and under a product.
     */
    public const LATEST_LIMIT = 3;

    public static function fromPost(NewsPost $post): NewsCard
    {
        $locale = app()->getLocale();
        $title = (string) $post->getTranslation('title', $locale, false);

        return new NewsCard(
            title: $title,
            slug: $post->slug,
            url: route('site.news.show', ['locale' => $locale, 'newsPost' => $post->slug]),
            excerpt: static::excerpt($post),
            publishedAt: $post->localPublishedAt(),
            image: $post->imageUrl('large'),
            imageAlt: ($post->imageAlts()[$locale] ?? '') ?: $title,
        );
    }

    /**
     * @param  iterable<NewsPost>  $posts
     * @return list<NewsCard>
     */
    public static function fromPosts(iterable $posts): array
    {
        $cards = [];

        foreach ($posts as $post) {
            $cards[] = static::fromPost($post);
        }

        return $cards;
    }

    /**
     * The latest posts visible in the current locale (none while the
     * directory says the locale has no news: no query then).
     *
     * @return list<NewsCard>
     */
    public static function latest(int $limit = self::LATEST_LIMIT, ?int $productId = null): array
    {
        $locale = app()->getLocale();

        if (! NewsDirectory::hasVisible($locale)) {
            return [];
        }

        $posts = NewsPost::query()
            ->visibleIn($locale)
            ->when($productId !== null, fn ($query) => $query->whereHas('products', fn ($products) => $products->whereKey($productId)))
            ->with('media')
            ->latestFirst()
            ->limit($limit)
            ->get();

        return static::fromPosts($posts);
    }

    /**
     * The excerpt in the current locale, else the start of the body.
     */
    public static function excerpt(NewsPost $post): string
    {
        $locale = app()->getLocale();
        $excerpt = trim((string) $post->getTranslation('excerpt', $locale, false));

        if ($excerpt !== '') {
            return $excerpt;
        }

        return Str::limit(StructuredData::plainText((string) $post->getTranslation('body', $locale, false)), self::EXCERPT_LENGTH, '…', preserveWords: true);
    }
}
