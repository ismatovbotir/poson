<?php

namespace App\Support\Content;

/**
 * Cleaning of untrusted video titles (plan 19, YT-S5): valid UTF-8, no tags,
 * no control or XML-invalid characters, collapsed spaces, capped by characters.
 * Applied on save and again where the text enters XML.
 */
final class VideoText
{
    public static function clean(mixed $text, int $max): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $text = mb_scrub($text);
        $text = strip_tags($text);
        // Control characters, DEL, C1, noncharacters U+FFFE/U+FFFF, bidi overrides.
        $text = (string) preg_replace('/[\x00-\x1F\x7F]|[\x{80}-\x{9F}]|[\x{FFFE}\x{FFFF}]|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = trim(mb_substr($text, 0, $max));

        return $text === '' ? null : $text;
    }
}
