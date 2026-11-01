<?php

namespace App\Mcp\Support;

/**
 * Text that came from users, customers or crawlers (names, titles,
 * referrers, user agents) on its way into an MCP tool result (plan 13 C23):
 * control and format characters are stripped, whitespace is collapsed and
 * the value is capped at about MAX characters. Tools put it only in fields
 * labelled as data, so Claude treats it as content, never as instructions.
 */
final class UntrustedText
{
    public const MAX = 200;

    public static function clean(mixed $value, int $max = self::MAX): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = (string) $value;

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Control (Cc) and format (Cf: zero-width, bidi overrides) characters become spaces.
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, max(1, $max - 1))).'…' : $text;
    }
}
