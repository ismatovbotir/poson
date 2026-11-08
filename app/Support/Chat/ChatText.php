<?php

namespace App\Support\Chat;

/**
 * The one text normalizer of the chat bot (plan 28 §2.2, CB-1). Used on
 * keywords when a rule is saved and on every incoming message, so both sides
 * always compare the same form:
 *
 *  1. invalid UTF-8 scrubbed (mb_scrub), cut to INPUT_MAX_BYTES before any work;
 *  2. lower case, ё → е;
 *  3. apostrophe variants removed (ʻ ʼ ' ` ‘ ’ ´ ′ ...): o'rnatish, oʻrnatish
 *     and ўрнатиш all become "ornatish";
 *  4. Cyrillic → Latin through a fixed table (Uzbek and Russian letters);
 *  5. everything that is not a letter or digit (punctuation, emoji, symbols) becomes a space, runs of
 *     spaces collapse, the result is cut to INPUT_MAX_CHARS characters.
 *
 * Admin text and input are never used as a pattern: the only regular
 * expression is one fixed, linear pass over the already capped text.
 */
final class ChatText
{
    public const INPUT_MAX_BYTES = 1000;

    public const INPUT_MAX_CHARS = 200;

    /**
     * Apostrophe-like characters that are dropped without a space.
     *
     * @var list<string>
     */
    private const APOSTROPHES = ["'", '`', 'ʻ', 'ʼ', '‘', '’', '´', '′', 'ʹ', 'ʽ', 'ʾ', 'ʿ', '‛', '՚'];

    /**
     * Typographic marks that separate words (non-ASCII, so the byte loop
     * below would keep them).
     *
     * @var list<string>
     */
    private const SEPARATORS = ['—', '–', '«', '»', '“', '”', '„', '…', '№', '•', '·', '€', '×', '°', '‑', '−', "\u{00A0}", "\u{2009}", "\u{202F}", '¿', '¡', '§', '¬', '©', '®', '™'];

    /**
     * Cyrillic (Uzbek and Russian) to Latin. ж → j and х → x follow Uzbek
     * Latin, so жавоб and javob are one word.
     *
     * @var array<string, string>
     */
    private const CYRILLIC = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'j', 'з' => 'z',
        'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'ў' => 'o', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h', 'ё' => 'e',
    ];

    /**
     * @param  int  $maxChars  Result length cap (characters)
     */
    public static function normalize(?string $text, int $maxChars = self::INPUT_MAX_CHARS): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = mb_strcut(mb_scrub($text, 'UTF-8'), 0, self::INPUT_MAX_BYTES, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(self::APOSTROPHES, '', $text);
        $text = str_replace(self::SEPARATORS, ' ', $text);
        $text = strtr($text, self::CYRILLIC);

        // One fixed pass on the already capped text: anything that is not a letter, mark or digit
        // (punctuation, emoji, symbols, control characters) separates words.
        $out = trim((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $text));

        return rtrim(mb_substr($out, 0, max(0, $maxChars), 'UTF-8'));
    }

    /**
     * Words of a normalized text.
     *
     * @return list<string>
     */
    public static function tokens(string $normalized): array
    {
        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * Whether the text looks like a product model or SKU: a word of at least
     * three characters with both a letter and a digit (ZD421, tsc-te210).
     */
    public static function looksLikeModel(string $normalized): bool
    {
        foreach (self::tokens($normalized) as $token) {
            if (strlen($token) >= 3 && strlen($token) <= 40 && self::hasLatinLetter($token) && self::hasDigit($token)) {
                return true;
            }
        }

        return false;
    }

    private static function hasLatinLetter(string $token): bool
    {
        return strpbrk($token, 'abcdefghijklmnopqrstuvwxyz') !== false;
    }

    private static function hasDigit(string $token): bool
    {
        return strpbrk($token, '0123456789') !== false;
    }
}
