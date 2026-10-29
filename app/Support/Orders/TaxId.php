<?php

namespace App\Support\Orders;

use App\Enums\BuyerType;

/**
 * Buyer tax ids (plan 11 v2 §1.4, security condition 12): a company's STIR
 * starts with 2 or 3 (9 digits); a sole proprietor (YaTT) gives a STIR
 * starting with 4–6 or a 14-digit PINFL starting with 3–6. Individuals
 * give none.
 */
final class TaxId
{
    public const COMPANY_STIR = '/^[23]\d{8}$/';

    public const SOLE_PROPRIETOR_STIR = '/^[4-6]\d{8}$/';

    public const PINFL = '/^[3-6]\d{13}$/';

    /**
     * Spaces and dashes removed (validation decides the rest); '' for a non-string.
     */
    public static function normalize(mixed $value): string
    {
        return is_string($value) ? mb_substr((string) preg_replace('/[\s\-]+/u', '', $value), 0, 32) : '';
    }

    public static function isValid(BuyerType $type, string $tin): bool
    {
        return match ($type) {
            BuyerType::Individual => $tin === '',
            BuyerType::Company => (bool) preg_match(self::COMPANY_STIR, $tin),
            BuyerType::SoleProprietor => (bool) preg_match(self::SOLE_PROPRIETOR_STIR, $tin) || (bool) preg_match(self::PINFL, $tin),
        };
    }

    /**
     * A 9-digit STIR (comparable with customers.inn), else null.
     */
    public static function stir(?string $tin): ?string
    {
        return is_string($tin) && preg_match('/^\d{9}$/', $tin) ? $tin : null;
    }

    /**
     * The validation message key for a type.
     */
    public static function messageKey(BuyerType $type): string
    {
        return $type === BuyerType::Company ? 'validation.stir' : 'validation.stir_or_pinfl';
    }
}
