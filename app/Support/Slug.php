<?php

namespace App\Support;

use Illuminate\Support\Str;

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

    public static function make(?string $value): string
    {
        return Str::slug(str_replace(self::APOSTROPHES, '', (string) $value));
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
}
