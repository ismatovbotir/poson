<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects low-price / discount wording (CLAUDE.md brand rule) in text that
 * appears on the public site. Patterns: config('brand.banned_patterns').
 * The message never repeats the input.
 */
class BrandSafeText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (static::violates($value)) {
            $fail('validation.brand_safe')->translate();
        }
    }

    /**
     * Whether the text contains banned wording (case-insensitive).
     */
    public static function violates(string $text): bool
    {
        $text = mb_strtolower($text);

        foreach ((array) config('brand.banned_patterns', []) as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
