<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One shared Latin slug per record (plan 03 D5).
 *
 * Uzbek apostrophes are removed before slugging, so "Oʻzbekcha gʻ" becomes
 * "ozbekcha-g" (not "o-zbekcha-g"). Cyrillic is transliterated by Str::slug.
 */
class Slug
{
    /**
     * Marks removed before slugging: ʻ U+02BB, ʼ U+02BC, ASCII ', ‘ U+2018, ’ U+2019 and `.
     */
    public const APOSTROPHES = ["\u{02BB}", "\u{02BC}", "'", "\u{2018}", "\u{2019}", '`'];

    /**
     * Longest slug base: leaves room for a "-123" suffix in a 255-character column.
     */
    public const MAX_BASE_LENGTH = 240;

    /**
     * Latin slug of a value, at most MAX_BASE_LENGTH characters (cut at a
     * character boundary, trailing "-" removed). Transliteration makes
     * Cyrillic names longer than the input, so the cap matters.
     */
    public static function make(?string $value): string
    {
        $slug = Str::slug(str_replace(self::APOSTROPHES, '', (string) $value));

        return trim(substr($slug, 0, self::MAX_BASE_LENGTH), '-');
    }

    /**
     * Slug that is not taken yet: "name", then "name-2", "name-3", ...
     *
     * @param  callable(string): bool  $exists
     */
    public static function unique(string $base, callable $exists): string
    {
        $base = $base !== '' ? $base : 'item';
        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Free slug in the model's "slug" column, ignoring the record itself.
     *
     * @param  class-string<Model>  $model
     */
    public static function uniqueFor(string $model, string $base, ?int $ignoreId = null, ?string $except = null): string
    {
        return static::unique($base, fn (string $slug): bool => $slug === $except || static::taken($model, $slug, $ignoreId));
    }

    /**
     * Save a model. When its slug was generated (not the stored one) and a
     * concurrent save took it between the check and the insert (unique
     * index), the slug is regenerated once, never the one that just clashed,
     * and the save retried. Should that clash too, a slug validation error.
     * Other unique violations (e.g. a product SKU) are rethrown untouched.
     *
     * @param  callable(string): string  $regenerate  receives the clashed slug, returns a new one
     *
     * @throws ValidationException
     * @throws UniqueConstraintViolationException
     */
    public static function saveGenerated(Model $model, bool $generated, callable $regenerate): void
    {
        try {
            $model->save();

            return;
        } catch (UniqueConstraintViolationException $exception) {
            if (! $generated || ! static::violates($exception, 'slug')) {
                throw $exception;
            }
        }

        $model->setAttribute('slug', $regenerate((string) $model->getAttribute('slug')));

        try {
            $model->save();
        } catch (UniqueConstraintViolationException $exception) {
            if (! static::violates($exception, 'slug')) {
                throw $exception;
            }

            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => __('admin.catalog.slug')])]);
        }
    }

    /**
     * Whether a unique violation is on the given column. Reads the driver
     * message only (not the SQL, which lists every column): MySQL names the
     * key ("for key 'products.products_slug_unique'"), SQLite the column
     * ("UNIQUE constraint failed: products.slug").
     */
    public static function violates(UniqueConstraintViolationException $exception, string $column): bool
    {
        $message = (string) ($exception->errorInfo[2] ?? $exception->getPrevious()?->getMessage() ?? '');

        if (preg_match("/for key '([^']+)'/", $message, $match) || preg_match('/UNIQUE constraint failed: (.+)$/', $message, $match)) {
            $message = $match[1];
        }

        return preg_match('/(^|[^a-z])'.preg_quote($column, '/').'([^a-z]|$)/', mb_strtolower($message)) === 1;
    }

    /**
     * Whether another record already uses the slug. Soft-deleted rows count
     * too (plan 03 D5): a trashed product keeps its slug, so restoring it
     * never clashes.
     *
     * @param  class-string<Model>  $model
     */
    public static function taken(string $model, string $slug, ?int $ignoreId = null): bool
    {
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true)
            ? $model::withTrashed()
            : $model::query();

        return $query
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
