<?php

namespace App\Support;

/**
 * A display colour "#RRGGBB" (plan 38, RD-1): the only way a stored hex
 * reaches a view. normalize() accepts strings of exactly "#" + 6 hex digits
 * (anchored \A…\z, so a trailing newline fails) and returns them upper case;
 * anything else (other lengths, names, arrays, numbers, null) is null.
 */
final class HexColor
{
    public const PATTERN = '/\A#[0-9A-Fa-f]{6}\z/';

    /**
     * Validation rule (use inside an array of rules, never in a "|" string).
     */
    public const RULE = 'regex:'.self::PATTERN;

    /** Above this relative luminance a colour counts as light (darker outlines and text). */
    public const LIGHT_LUMINANCE = 0.6;

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        return strtoupper($value);
    }

    /**
     * WCAG relative luminance (0 black … 1 white) of a valid hex; 0.0 for an invalid one.
     */
    public static function luminance(mixed $value): float
    {
        $hex = self::normalize($value);

        if ($hex === null) {
            return 0.0;
        }

        $channel = static function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
    }

    public static function isLight(mixed $value): bool
    {
        return self::luminance($value) > self::LIGHT_LUMINANCE;
    }
}
