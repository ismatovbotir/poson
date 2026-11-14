<?php

namespace App\Support\Chat;

/**
 * A whole message that is an order or repair number (plan 28 C3, CB-28):
 * "O-2026-0001", "o 2026 0001", "#R-2026-000123", "O20260001". Such a
 * message goes to the status lookup before rules, product search and the
 * unmatched list (otherwise "o 2026 0001" would look like a model).
 *
 * One fixed pattern (anchored, /D so a trailing newline fails, linear) over
 * the trimmed message, cut to MAX_LENGTH first. Only the O and R prefixes:
 * a test order number (T-…) never matches. A Cyrillic О / Р typed on a
 * Russian keyboard counts as the Latin letter.
 */
final class LookupNumber
{
    public const ORDER = 'order';

    public const REPAIR = 'repair';

    public const MAX_LENGTH = 30;

    private const PATTERN = '/^#?\s*([OR])[\s-]?(\d{4})[\s-]?(\d{4,6})$/iuD';

    /**
     * @return array{kind: self::ORDER|self::REPAIR, number: string}|null
     */
    public static function parse(?string $text): ?array
    {
        if ($text === null || $text === '' || strlen($text) > self::MAX_LENGTH * 4) {
            return null;
        }

        $text = trim(strtr(mb_scrub($text, 'UTF-8'), ['О' => 'O', 'о' => 'O', 'Р' => 'R', 'р' => 'R']));

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH || preg_match(self::PATTERN, $text, $matches) !== 1) {
            return null;
        }

        $prefix = strtoupper($matches[1]);

        return [
            'kind' => $prefix === 'O' ? self::ORDER : self::REPAIR,
            'number' => $prefix.'-'.$matches[2].'-'.$matches[3],
        ];
    }
}
