<?php

namespace App\Services\Payments\Uzqr;

/**
 * Checks of a qr_text before it is stored or rendered (condition 42, 46):
 * printable ASCII, 1–2000 characters; when it is an EMVCo merchant QR
 * (TLV starting "000201") its tag 54 (amount) must equal ours.
 */
final class UzqrQrText
{
    public const MAX = 2000;

    public static function isValid(mixed $text): bool
    {
        return is_string($text) && preg_match('/^[\x20-\x7E]{1,'.self::MAX.'}$/', $text) === 1;
    }

    /**
     * The amount (tiyin) of an EMVCo TLV payload's tag 54, null when the
     * text is no EMVCo payload or has no tag 54, false when it is malformed.
     */
    public static function emvAmount(string $text): int|false|null
    {
        if (! str_starts_with($text, '000201')) {
            return null;
        }

        $position = 0;
        $length = strlen($text);

        while ($position < $length) {
            if ($position + 4 > $length) {
                return false;
            }

            $tag = substr($text, $position, 2);
            $size = substr($text, $position + 2, 2);

            if (! ctype_digit($tag) || ! ctype_digit($size) || $position + 4 + (int) $size > $length) {
                return false;
            }

            $value = substr($text, $position + 4, (int) $size);

            if ($tag === '54') {
                return UzqrAmount::toTiyin($value) ?? false;
            }

            $position += 4 + (int) $size;
        }

        return null;
    }

    /**
     * The QR may be shown for this amount: valid text and no conflicting EMVCo amount.
     */
    public static function matchesAmount(string $text, int $tiyin): bool
    {
        $amount = self::emvAmount($text);

        return $amount === null || $amount === $tiyin;
    }
}
