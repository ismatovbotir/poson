<?php

namespace App\Support\Monitoring;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation for the monitoring ping URL secret: https and an allowed host
 * (see PingUrl). The message never repeats the value.
 */
final class PingUrlRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PingUrl::isAllowed(is_string($value) ? $value : null)) {
            $fail('admin.settings.integrations.monitoring.ping_url_invalid')->translate();
        }
    }
}
