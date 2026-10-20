<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts any input that App\Support\Phone can normalize to "+998XXXXXXXXX".
 */
class UzPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Phone::normalize($value) === null) {
            $fail('validation.uz_phone')->translate();
        }
    }
}
