<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Portable LIKE search (SQLite in dev, MySQL in production).
 *
 * Use with an explicit escape character, which both databases accept:
 *     ->whereRaw("lower(code) like ? escape '!'", [Search::likePattern($term)])
 * Uzbek apostrophes (ʻ ʼ ' ‘ ’ `) become the single-character wildcard "_",
 * so "o'zbek" also finds "oʻzbek".
 *
 * Note: SQLite's lower() folds ASCII only, so in dev a Cyrillic term matches
 * only when the letters' case matches; MySQL folds all of Unicode.
 */
class Search
{
    public const ESCAPE = '!';

    public static function likePattern(string $term): string
    {
        $term = mb_strtolower(trim($term));
        $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $term = str_replace(Slug::APOSTROPHES, '_', $term);

        return "%{$term}%";
    }

    /**
     * Normalized form of products.search_text (plan 03 D7): lower case (all of
     * Unicode, in PHP), Uzbek apostrophes removed, whitespace collapsed.
     */
    public static function normalize(?string $text): string
    {
        $text = mb_strtolower(str_replace(Slug::APOSTROPHES, '', (string) $text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * LIKE pattern for a column stored with normalize() (e.g. search_text):
     * the term is normalized the same way, so "o'zbek", "oʻzbek" and "ozbek"
     * all match, and Cyrillic case doesn't matter even on SQLite.
     */
    public static function normalizedPattern(string $term): string
    {
        return static::likePattern(static::normalize($term));
    }

    /**
     * OR-match the term against each locale value of a translatable json
     * column (name->uz, name->ru, name->en), never against the json keys.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public static function orWhereTranslatable(BuilderContract $query, string $column, string $term): void
    {
        $pattern = static::likePattern($term);
        $grammar = $query instanceof EloquentBuilder ? $query->getQuery()->getGrammar() : $query->getGrammar();

        foreach (config('app.supported_locales') as $locale) {
            $query->orWhereRaw('lower('.$grammar->wrap("{$column}->{$locale}").") like ? escape '!'", [$pattern]);
        }
    }

    /**
     * Where the term matches one of the locale values of a translatable column.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public static function whereTranslatable(BuilderContract $query, string $column, string $term): void
    {
        $query->where(fn ($inner) => static::orWhereTranslatable($inner, $column, $term));
    }
}
