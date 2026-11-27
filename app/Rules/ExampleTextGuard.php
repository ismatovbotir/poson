<?php

namespace App\Rules;

use App\Support\CompanySettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Keeps contact data and identifiers out of the text an Admin teaches an
 * agent with (plan 46 §2.2, ED46-2, ED46-5): examples, service facts,
 * hints, instructions, rules and knowledge must not carry a real client's
 * phone, card, INN, e-mail, link, handle or a run of 5+ digits.
 *
 * The text is normalised first so that obfuscation does not slip through:
 * NFKC (fullwidth digits and letters), invisible format characters removed
 * (a phone split by zero-width characters), every Unicode digit as ASCII,
 * dashes unified, "[.]", "(.)", "(dot)", "[at]", "(at)" turned back into
 * "." and "@", and separators (spaces, dots, dashes, brackets, slashes)
 * between digits collapsed ("9 9 8 9 0 …", "99-89-01"). Then it blocks "@",
 * "t.me", "www.", a scheme and bare domains ("poson.uz"), and any run of
 * 5+ digits. The only exception is an exact occurrence of the company's own
 * contacts from Settings (phones, e-mail, Telegram link), cut out first.
 * Spelled-out digits and similar tricks are not caught: the Admin is
 * the last gate (promote tick, plan 46 R6). The message never repeats the
 * input.
 */
class ExampleTextGuard implements ValidationRule
{
    private const TLDS = 'uz|com|ru|org|net|io|me|info|biz|xyz|app|dev|kz|su|ai|co|tv|ly';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $value !== '' && static::violates($value)) {
            $fail('validation.example_text_guard')->translate();
        }
    }

    /**
     * Whether the text carries contact data or an identifier.
     *
     * @param  list<string>|null  $allowedContacts  Exact company contacts to ignore (null: read them from Settings)
     */
    public static function violates(string $text, ?array $allowedContacts = null): bool
    {
        $text = static::withoutContacts($text, $allowedContacts ?? static::companyContacts());
        $text = static::normalise($text);

        if ($text === '') {
            return false;
        }

        if (str_contains($text, '@') || str_contains($text, '://') || preg_match('~(?<![\p{L}\d])(?:t\.me|www\.)~u', $text) === 1) {
            return true;
        }

        // One label before the ending, anchored at a label start: linear on any input (no nested repeats).
        if (preg_match('~(?<![\p{L}\d-])[\p{L}\d-]+\.(?:'.self::TLDS.')(?![\p{L}\d-])~u', $text) === 1) {
            return true;
        }

        $collapsed = $text;

        do {
            $previous = $collapsed;
            $collapsed = (string) preg_replace('/(\d)[\s.\-()\/_,]+(?=\d)/u', '$1', $collapsed);
        } while ($collapsed !== $previous);

        return preg_match('/\d{5,}/', $collapsed) === 1;
    }

    /**
     * NFKC, invisible characters removed, Unicode digits as ASCII, unified dashes
     * and spaces, de-obfuscated dots and "at", lowercase.
     */
    public static function normalise(string $text): string
    {
        $normalised = \Normalizer::normalize($text, \Normalizer::FORM_KC);
        $text = is_string($normalised) ? $normalised : $text;
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/\p{Cf}/u', '', $text);
        $text = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', $text);
        $text = (string) preg_replace('/\p{Zs}/u', ' ', $text);
        $text = (string) preg_replace('/\p{Pd}|\x{2212}/u', '-', $text);
        $text = (string) preg_replace_callback('/\p{Nd}/u', function (array $match): string {
            $value = \IntlChar::charDigitValue(mb_ord($match[0], 'UTF-8'));

            return $value >= 0 ? (string) $value : '#';
        }, $text);

        $text = mb_strtolower($text);
        $text = (string) preg_replace('/\s*[\[({<]\s*(?:\.|dot|nuqta|точка)\s*[\])}>]\s*/u', '.', $text);
        $text = (string) preg_replace('/\s*[\[({<]\s*(?:@|at|dog|собака)\s*[\])}>]\s*/u', '@', $text);

        return trim($text);
    }

    /**
     * Cut the exact company contacts out of the text.
     *
     * @param  list<string>  $contacts
     */
    private static function withoutContacts(string $text, array $contacts): string
    {
        foreach ($contacts as $contact) {
            if ($contact !== '') {
                $text = str_ireplace($contact, ' ', $text);
            }
        }

        return $text;
    }

    /**
     * The company's own phones, e-mail and Telegram link (Settings), in every stored form.
     *
     * @return list<string>
     */
    private static function companyContacts(): array
    {
        $company = app(CompanySettings::class);

        // Once per request: the memo lives as long as the (scoped) CompanySettings instance.
        /** @var \WeakMap<CompanySettings, list<string>> $memo */
        static $memo = new \WeakMap;

        if (isset($memo[$company])) {
            return $memo[$company];
        }

        $contacts = [$company->email(), $company->telegramUrl()];

        foreach ($company->phones() as $phone) {
            $contacts[] = $phone['number'];
            $contacts[] = $phone['display'];
        }

        foreach ($company->social() as $link) {
            $contacts[] = $link['url'];
        }

        $contacts = array_values(array_filter(array_unique(array_map('strval', $contacts)), fn (string $contact): bool => mb_strlen($contact) >= 5));
        usort($contacts, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $memo[$company] = $contacts;
    }
}
