<?php

namespace App\Support;

/**
 * Plain text from user input (plan 31): tags stripped, control (Cc, except a
 * line break when multiline) and format (Cf) characters and line / paragraph
 * separators removed, whitespace trimmed, runs of blank lines collapsed.
 * Output is still escaped by the views; this only keeps junk out of the database.
 */
class PlainText
{
    public static function clean(?string $value, bool $multiline = false): string
    {
        $text = strip_tags((string) $value);
        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $text);
        $text = (string) preg_replace($multiline ? '/[^\P{Cc}\n]|\p{Cf}/u' : '/\p{Cc}|\p{Cf}/u', $multiline ? '' : ' ', $text);

        if (! $multiline) {
            return trim((string) preg_replace('/\s+/u', ' ', $text));
        }

        $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/', "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
