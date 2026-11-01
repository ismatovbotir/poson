<?php

namespace App\Mcp\Support\Presenters;

/**
 * Money in MCP output is always integer tiyin: no float, no formatting, no
 * currency symbol (plan 13 M2-S4).
 */
final class Money
{
    public static function tiyin(mixed $amount): int
    {
        return is_numeric($amount) ? (int) $amount : 0;
    }

    public static function tiyinOrNull(mixed $amount): ?int
    {
        return is_numeric($amount) ? (int) $amount : null;
    }
}
