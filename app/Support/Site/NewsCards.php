<?php

namespace App\Support\Site;

use App\Data\Site\NewsCard;
use App\Enums\NewsKind;
use App\Models\NewsPost;
use App\Support\Catalog\CategoryTree;
use Illuminate\Support\Str;

/**
 * Maps visible news posts and articles to App\Data\Site\NewsCard in the
 * current locale (the card links the post's own kind route).
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

    /**
     * "Useful articles" on a category or product page.
     */
    public const ARTICLES_LIMIT = 3;

    public static function fromPost(NewsPost $post): NewsCard
    {
        $locale = app()->getLocale();
        $title = (string) $post->getTranslation('title', $locale, false);

        return new NewsCard(
            title: $title,
            slug: $post->slug,
            url: $post->url($locale),
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
     * The latest news posts (never articles) visible in the current locale
     * (none while the directory says the locale has no news: no query then).
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
            ->ofKind(NewsKind::News)
            ->visibleIn($locale)
            ->when($productId !== null, fn ($query) => $query->whereHas('products', fn ($products) => $products->whereKey($productId)))
            ->with('media')
            ->latestFirst()
            ->limit($limit)
            ->get();

        return static::fromPosts($posts);
    }

    /**
     * Articles visible in the current locale that are linked (category_news_post)
     * to the category, one of its ancestors or one of its descendants, newest
     * first: the "Useful articles" block of category and product pages. Two
     * queries (posts, then their media; none while the locale has no articles).
     *
     * @return list<NewsCard>
     */
    public static function articlesFor(int $categoryId, int $limit = self::ARTICLES_LIMIT): array
    {
        $locale = app()->getLocale();

        if (! NewsDirectory::hasVisible($locale, NewsKind::Article) || CategoryTree::find($categoryId) === null) {
            return [];
        }

        $categoryIds = [$categoryId, ...CategoryTree::ancestorIds($categoryId), ...CategoryTree::descendantIds($categoryId)];

        $posts = NewsPost::query()
            ->ofKind(NewsKind::Article)
            ->visibleIn($locale)
            ->whereHas('categories', fn ($categories) => $categories->whereIn('categories.id', $categoryIds))
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
