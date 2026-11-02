<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number of any country (plan 14 v2 §8 / §20): the Uzbek forms that
 * Phone::normalize() takes, or an international number with + or 00 and the
 * country code (8-15 digits). Non-geographic and premium codes are refused
 * (config site.reserved_phone_prefixes, TG-41).
 *
 * public = a public site entry point: with the kill switch
 * site.foreign_phones_enabled off, only Uzbek numbers pass (TG-44).
 */
class PhoneNumber implements ValidationRule
{
    public function __construct(private readonly bool $public = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = is_string($value) ? Phone::normalizeAny($value) : null;

        if ($normalized === null || Phone::isReserved($normalized)) {
            $fail('validation.phone_any')->translate();

            return;
        }

        if ($this->public && ! config('site.foreign_phones_enabled') && ! Phone::isUzbek($normalized)) {
            $fail('validation.uz_phone')->translate();
        }
    }
}
