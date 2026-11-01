<?php

namespace App\Actions\Mcp;

use App\Enums\ApiTokenType;
use App\Enums\McpRevokeReason;
use App\Models\ApiToken;
use App\Models\User;

/**
 * Hourly safety net for plan 13 C8 (`mcp:sweep`): spatie role events are
 * off, and mass updates skip UserObserver, so any user who holds MCP access
 * or an active MCP token but is no longer an active Admin loses it here
 * (reason deactivated or role_lost; tokens left without a grant: admin).
 * EnsureMcpUser already refuses such
 * users on every request; this revokes the tokens and alerts.
 */
class SweepMcpAccess
{
    public function __construct(protected RevokeMcpAccess $revokeAccess) {}

    /**
     * @return int Users whose access was revoked
     */
    public function handle(): int
    {
        $userIds = User::query()->whereNotNull('mcp_access_granted_at')->pluck('id')
            ->merge(ApiToken::query()->ofType(ApiTokenType::Mcp)->whereNull('revoked_at')->whereNotNull('user_id')->pluck('user_id'))
            ->unique();

        $revoked = 0;

        foreach (User::query()->whereKey($userIds)->with('roles')->get() as $user) {
            if ($user->isActiveAdmin() && $user->hasMcpAccess()) {
                continue;
            }

            $reason = match (true) {
                ! $user->is_active => McpRevokeReason::Deactivated,
                ! $user->isActiveAdmin() => McpRevokeReason::RoleLost,
                // Active tokens left without a grant (e.g. a mass update cleared it).
                default => McpRevokeReason::Admin,
            };

            $hadGrant = $user->hasMcpAccess();

            if ($this->revokeAccess->handle($user, $reason) > 0 || $hadGrant) {
                $revoked++;
            }
        }

        return $revoked;
    }
}
