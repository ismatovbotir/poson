<?php

namespace App\Support\Content\AiFill;

/**
 * Puts the right Uzbek (Latin) apostrophes into text an AI wrote with plain
 * or typographic quotes: oʻ and gʻ take ʻ (U+02BB), the tutuq belgisi
 * everywhere else between letters is ʼ (U+02BC): oʻzbek, gʻoya, maʼlumot.
 * HTML tags and attributes are left alone.
 */
final class UzApostrophes
{
    private const QUOTES = "'\u{2018}\u{2019}\u{0060}\u{00B4}\u{02BB}\u{02BC}";

    public static function normalize(string $text): string
    {
        $parts = preg_split('/(<[^>]*>)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $text;
        }

        foreach ($parts as $index => $part) {
            if ($part === '' || str_starts_with($part, '<')) {
                continue;
            }

            $parts[$index] = (string) preg_replace_callback(
                '/(\pL)['.self::QUOTES.'](?=\pL)/u',
                fn (array $match): string => $match[1].(in_array($match[1], ['o', 'O', 'g', 'G'], true) ? "\u{02BB}" : "\u{02BC}"),
                $part,
            );
        }

        return implode('', $parts);
    }
}
