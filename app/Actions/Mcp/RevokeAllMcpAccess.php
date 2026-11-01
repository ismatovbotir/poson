<?php

namespace App\Actions\Mcp;

use App\Enums\ApiTokenType;
use App\Enums\McpRevokeReason;
use App\Models\ApiToken;
use App\Models\User;

/**
 * "Revoke all MCP access" (plan 13 §7, C22): every user holding the grant
 * or an active MCP token loses it (RevokeMcpAccess, reason revoke_all).
 * Price API tokens are never touched. Returns the number of revoked tokens.
 */
class RevokeAllMcpAccess
{
    public function __construct(protected RevokeMcpAccess $revokeAccess) {}

    public function handle(User $by, ?string $ip): int
    {
        $userIds = User::query()->whereNotNull('mcp_access_granted_at')->pluck('id')
            ->merge(ApiToken::query()->ofType(ApiTokenType::Mcp)->whereNull('revoked_at')->whereNotNull('user_id')->pluck('user_id'))
            ->unique();

        $revoked = 0;

        foreach (User::query()->whereKey($userIds)->get() as $user) {
            $revoked += $this->revokeAccess->handle($user, McpRevokeReason::RevokeAll, $by, $ip);
        }

        return $revoked;
    }
}
