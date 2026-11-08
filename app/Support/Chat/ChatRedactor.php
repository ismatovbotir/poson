<?php

namespace App\Support\Chat;

/**
 * Masks personal and secret-looking data in a visitor's message BEFORE it is
 * stored as an "unmatched" phrase (plan 28, CB-10). Everything masked becomes
 * "#". Order matters:
 *
 *  1. invalid UTF-8 scrubbed, cut to 1000 bytes, control and format characters
 *     turned into spaces;
 *  2. look-alikes folded so they cannot hide a number: every Unicode space
 *     (NBSP, thin space ...) becomes a space, every dash (en dash, minus ...)
 *     becomes "-", every Unicode digit (Arabic-Indic, fullwidth ...) becomes
 *     an ASCII digit;
 *  3. secrets (as in Monitoring\Redactor): bot tokens, "Bearer ..." and
 *     "key=..." values, hex or alphanumeric runs of 24+ characters;
 *  4. emails (anything around an "@");
 *  5. links: scheme or "www." or "t.me/", and bare domains of common
 *     endings (poson.uz/abc);
 *  6. passports: two letters then 7 digits, with or without a space;
 *  7. any run of 5 or more digits, also across spaces, dots, dashes, slashes,
 *     underscores and brackets: Uzbek phones in every form (+998 90 123 45 67,
 *     90 123-45-67, 901234567, 8 90 ...), cards, INN, PINFL, order numbers.
 *
 * Fixed patterns only, linear on the capped input (no nested open-ended
 * repeats). Short digit groups stay readable ("ZD421 printer"), but a model
 * number followed by a long number ("ZD421 300 600") is masked as a whole.
 */
final class ChatRedactor
{
    public const MASK = '#';

    public static function clean(?string $text, int $maxChars = 200): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = mb_strcut(mb_scrub($text, 'UTF-8'), 0, ChatText::INPUT_MAX_BYTES, 'UTF-8');
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text);
        $text = (string) preg_replace('/\p{Zs}/u', ' ', $text);
        $text = (string) preg_replace('/\p{Pd}|\x{2212}/u', '-', $text);
        $text = self::asciiDigits($text);

        $text = (string) preg_replace('/\d{5,15}:[A-Za-z0-9_-]{30,}/', self::MASK, $text);
        $text = (string) preg_replace('/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', self::MASK, $text);
        // A secret word followed by a value, with or without ":", "=" or "-": "parol: x", "password x".
        $text = (string) preg_replace('/\b(?:password|passwd|parol|пароль|token|токен|kod|код|pin)\b\s*[:=\-]?\s*\S+/iu', self::MASK, $text);
        $text = (string) preg_replace('/\b[\w.-]*(?:password|passwd|secret|token|key|sign\w*|auth\w*)[\w.-]*\s*=\s*\S*/i', self::MASK, $text);
        $text = (string) preg_replace('/(?=[A-Za-z0-9+_-]*\d)(?=[A-Za-z0-9+_-]*[A-Za-z])[A-Za-z0-9+_-]{24,}={0,2}/', self::MASK, $text);
        $text = (string) preg_replace('/\b[A-Fa-f0-9]{24,}\b/', self::MASK, $text);

        $text = (string) preg_replace('/\S*@\S*/u', self::MASK, $text);
        $text = (string) preg_replace('~(?:[a-z][a-z0-9+.-]{1,10}://|www\.|t\.me/)\S*~iu', self::MASK, $text);
        $text = (string) preg_replace('~[\w-]+(?:\.[\w-]+)*\.(?:uz|com|ru|org|net|io|me|info|biz|xyz|app|dev|co)(?![\w-])(?:/\S*)?~iu', self::MASK, $text);
        $text = (string) preg_replace('/(?<![\p{L}\d])\p{L}{2}\s?\d{7}(?!\d)/u', self::MASK, $text);
        $text = (string) preg_replace('/\+?\d(?:[ .\-()\/_]{0,3}\d){4,}/', self::MASK, $text);
        $text = (string) preg_replace('/#(?:\s*#)+/u', self::MASK, $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim(mb_substr($text, 0, max(0, $maxChars)));
    }

    /**
     * Every Unicode decimal digit as its ASCII digit (Arabic-Indic, fullwidth,
     * Devanagari ...), by its numeric value.
     */
    private static function asciiDigits(string $text): string
    {
        return (string) preg_replace_callback('/\p{Nd}/u', function (array $match): string {
            $value = \IntlChar::charDigitValue(mb_ord($match[0], 'UTF-8'));

            return $value >= 0 ? (string) $value : self::MASK;
        }, $text);
    }
}
