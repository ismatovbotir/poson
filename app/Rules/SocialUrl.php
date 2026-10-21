<?php

namespace App\Rules;

use App\Enums\SocialNetwork;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An https link on one of the network's hosts (SocialNetwork::allows()).
 * Without a known network the link is rejected.
 */
class SocialUrl implements ValidationRule
{
    public function __construct(protected ?SocialNetwork $network) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->network === null || ! $this->network->allows($value)) {
            $fail('validation.social_url')->translate(['hosts' => implode(', ', $this->network?->hosts() ?? [])]);
        }
    }
}
