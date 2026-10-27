<?php

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\User;

/**
 * Settings → API tokens (plan 09). api-tokens.manage is seeded but granted
 * to no role besides Admin (who also passes through Gate::before).
 */
class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('api-tokens.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('api-tokens.manage');
    }

    public function revoke(User $user, ApiToken $apiToken): bool
    {
        return $user->can('api-tokens.manage') && ! $apiToken->isRevoked();
    }
}
