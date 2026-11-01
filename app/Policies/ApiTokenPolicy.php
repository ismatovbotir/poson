<?php

namespace App\Policies;

use App\Enums\ApiTokenType;
use App\Models\ApiToken;
use App\Models\User;

/**
 * Settings → API tokens (plan 09). api-tokens.manage is seeded but granted
 * to no role besides Admin (who also passes through Gate::before). Only
 * price API tokens are managed here; MCP tokens live on Settings → MCP
 * access (plan 13, C5). Gate::before lets an Admin past this policy, so the
 * screen and RevokeApiToken also filter by type themselves.
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
        return $apiToken->type === ApiTokenType::PriceApi && $user->can('api-tokens.manage') && ! $apiToken->isRevoked();
    }
}
