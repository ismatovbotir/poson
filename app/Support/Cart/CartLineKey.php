<?php

namespace App\Support\Cart;

/**
 * Key of a cart line (plan 11 v2 §1.1): "p{productId}" for a product,
 * "p{productId}-r{rollTypeId}" for a label product in a roll type. It is
 * the only line reference the browser ever sees (no cart or item ids), so
 * every key coming back is parsed with the strict PATTERN first.
 */
final class CartLineKey
{
    public const PATTERN = '/^p(\d{1,10})(?:-r(\d{1,10}))?$/';

    public static function make(int $productId, ?int $rollTypeId = null): string
    {
        return 'p'.$productId.($rollTypeId !== null ? '-r'.$rollTypeId : '');
    }

    /**
     * The product and roll type ids of a well-formed key, else null.
     *
     * @return array{productId: int, rollTypeId: int|null}|null
     */
    public static function parse(mixed $lineKey): ?array
    {
        if (! is_string($lineKey) || preg_match(self::PATTERN, $lineKey, $matches) !== 1) {
            return null;
        }

        $productId = (int) $matches[1];
        $rollTypeId = isset($matches[2]) ? (int) $matches[2] : null;

        // Ids start at 1 and fit an unsigned BIGINT anyway; leading zeros would make a second key for one line.
        if ($productId < 1 || ($rollTypeId !== null && $rollTypeId < 1) || self::make($productId, $rollTypeId) !== $lineKey) {
            return null;
        }

        return ['productId' => $productId, 'rollTypeId' => $rollTypeId];
    }

    public static function isValid(mixed $lineKey): bool
    {
        return self::parse($lineKey) !== null;
    }
}
