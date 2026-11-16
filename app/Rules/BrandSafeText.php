<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects low-price / discount wording (CLAUDE.md brand rule) in text that
 * appears on the public site. Patterns: config('brand.banned_patterns').
 * The message never repeats the input.
 *
 * A context (plan 31) may name narrow allowances in config('brand.allowances.{context}'):
 * phrases that are blanked out before the banned patterns run. The
 * careers context has one ("sales manager", "sales department", "B2B sales"…
 * as a job or department name); "sale", "on sale" or "sales!" stay blocked
 * there too. The product context allows POS/service wording ("point of
 * sale", "after-sales service", "sales receipt"). Allowances apply per
 * sentence and never in a sentence with "!" or "%". Without a context
 * nothing is allowed.
 */
class BrandSafeText implements ValidationRule
{
    public const CAREERS = 'careers';

    /**
     * Product texts: "point of sale", "after-sales (service|support)" and
     * "sales receipt/report/invoice/slip" are allowed.
     */
    public const PRODUCT = 'product';

    public function __construct(private readonly ?string $context = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (static::violates($value, $this->context)) {
            $fail('validation.brand_safe')->translate();
        }
    }

    /**
     * Whether the text contains banned wording (case-insensitive), after the
     * allowances of the given context are removed.
     *
     * The text is NFKC-normalised first (fullwidth "ｓａｌｅ", "％", "！" become
     * ASCII) and invisible format characters are stripped. Allowances apply per
     * sentence (split on ".", ";" and newlines) and are skipped for a sentence
     * that contains "!", "%", "‼", "⁉" or a number followed by "%", so
     * "point of sale, up to 50% off" stays blocked.
     */
    public static function violates(string $text, ?string $context = null): bool
    {
        $text = static::normalise($text);

        if ($context !== null) {
            $text = static::applyAllowances($text, (array) config("brand.allowances.{$context}", []));
        }

        foreach ((array) config('brand.banned_patterns', []) as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * NFKC, then strip invisible format characters (zero-width space, soft
     * hyphen…) so they can't split a word, then lowercase.
     */
    private static function normalise(string $text): string
    {
        $normalised = \Normalizer::normalize($text, \Normalizer::FORM_KC);

        if (is_string($normalised)) {
            $text = $normalised;
        }

        $stripped = preg_replace('/\p{Cf}/u', '', $text);

        return mb_strtolower(is_string($stripped) ? $stripped : $text);
    }

    /**
     * Blank out the allowance phrases sentence by sentence; a sentence with
     * promo punctuation or a percent keeps its text untouched.
     *
     * @param  array<int, string>  $allowances
     */
    private static function applyAllowances(string $text, array $allowances): string
    {
        if ($allowances === []) {
            return $text;
        }

        $parts = preg_split('/([.\n;])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $text;
        }

        foreach ($parts as $index => $sentence) {
            if (preg_match('/[!%‼⁉]|\d\s*%/u', $sentence) === 1) {
                continue;
            }

            foreach ($allowances as $allowance) {
                $sentence = (string) preg_replace($allowance, ' ', $sentence);
            }

            $parts[$index] = $sentence;
        }

        return implode('', $parts);
    }
}
