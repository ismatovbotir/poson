<?php

namespace App\Rules;

use App\Support\Site\BannerLink as BannerLinkTarget;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A banner link: an internal path ("/uz/catalog/...", never "//") or an
 * https URL (App\Support\Site\BannerLink). Empty values are left to "nullable".
 */
class BannerLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! BannerLinkTarget::isValid(trim($value))) {
            $fail('admin.banners.errors.link')->translate();
        }
    }
}
