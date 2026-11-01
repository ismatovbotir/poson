<?php

namespace App\Support\Content;

use App\Models\NewsPost;
use App\Support\Site\StructuredData;

/**
 * SEO and completeness audit of a news post or article (plan 13 S21).
 * Returns issue codes only: {code, locale, severity}; never raw content.
 * locale is null for issues of the whole post.
 *
 * Per-locale checks beyond the title run for uz always and for ru/en only
 * when that locale has a title (an untranslated locale is not published).
 * Cover and links use the loaded media / counts when present.
 */
final class PostAudit
{
    public const META_TITLE_MAX = 60;

    public const META_DESCRIPTION_MIN = 70;

    public const META_DESCRIPTION_MAX = 160;

    public const BODY_MIN_CHARS = 300;

    /**
     * @return list<array{code: string, locale: string|null, severity: string}>
     */
    public static function issues(NewsPost $post): array
    {
        $issues = [];
        $add = function (string $code, ?string $locale, string $severity) use (&$issues): void {
            $issues[] = ['code' => $code, 'locale' => $locale, 'severity' => $severity];
        };
        $default = (string) config('app.locale', 'uz');

        foreach (config('app.supported_locales') as $locale) {
            $title = self::text($post, 'title', $locale);

            if ($title === '') {
                $add('title_missing', $locale, $locale === $default ? 'error' : 'warning');

                if ($locale !== $default) {
                    continue;
                }
            }

            $metaTitle = self::text($post, 'meta_title', $locale);

            if ($metaTitle === '') {
                $add('meta_title_missing', $locale, 'warning');
            } elseif (mb_strlen($metaTitle) > self::META_TITLE_MAX) {
                $add('meta_title_too_long', $locale, 'warning');
            }

            $metaDescription = self::text($post, 'meta_description', $locale);
            $length = mb_strlen($metaDescription);

            if ($metaDescription === '') {
                $add('meta_description_missing', $locale, 'warning');
            } elseif ($length < self::META_DESCRIPTION_MIN || $length > self::META_DESCRIPTION_MAX) {
                $add('meta_description_length', $locale, 'warning');
            }

            if (self::text($post, 'excerpt', $locale) === '') {
                $add('excerpt_missing', $locale, 'warning');
            }

            if (mb_strlen(StructuredData::plainText(self::text($post, 'body', $locale))) < self::BODY_MIN_CHARS) {
                $add('body_short', $locale, 'warning');
            }
        }

        if (! self::hasCover($post)) {
            $add('cover_missing', null, 'warning');
        }

        if (self::linkCount($post) === 0) {
            $add('links_missing', null, 'info');
        }

        return $issues;
    }

    public static function count(NewsPost $post): int
    {
        return count(self::issues($post));
    }

    private static function text(NewsPost $post, string $field, string $locale): string
    {
        $value = $post->getTranslation($field, $locale, false);

        return is_string($value) ? trim($value) : '';
    }

    private static function hasCover(NewsPost $post): bool
    {
        if ($post->relationLoaded('media')) {
            return $post->media->contains('collection_name', $post->imageCollection());
        }

        return $post->media()->where('collection_name', $post->imageCollection())->exists();
    }

    private static function linkCount(NewsPost $post): int
    {
        $attributes = $post->getAttributes();

        if (isset($attributes['products_count'], $attributes['categories_count'])) {
            return (int) $attributes['products_count'] + (int) $attributes['categories_count'];
        }

        return $post->products()->count() + $post->categories()->count();
    }
}
