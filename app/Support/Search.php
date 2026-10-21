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
