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
 * phrases that are blanked out before the banned patterns run. Only the
 * careers context has one ("sales manager", "sales department", "B2B sales"…
 * as a job or department name); "sale", "on sale" or "sales!" stay blocked
 * there too. Without a context nothing is allowed.
 */
class BrandSafeText implements ValidationRule
{
    public const CAREERS = 'careers';

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
     */
    public static function violates(string $text, ?string $context = null): bool
    {
        $text = mb_strtolower($text);

        if ($context !== null) {
            foreach ((array) config("brand.allowances.{$context}", []) as $allowance) {
                $text = (string) preg_replace($allowance, ' ', $text);
            }
        }

        foreach ((array) config('brand.banned_patterns', []) as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
