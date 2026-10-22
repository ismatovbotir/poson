<?php

namespace App\Rules;

use App\Support\Catalog\HttpsUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A valid external https link (App\Support\Catalog\HttpsUrl), e.g. a brand
 * website / the software "Try" link. Empty values are left to "nullable".
 */
class HttpsLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! HttpsUrl::isValid(trim($value))) {
            $fail('admin.brands.errors.website_https')->translate();
        }
    }
}
