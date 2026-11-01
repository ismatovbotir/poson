<?php

namespace App\Actions\Mcp;

use App\Enums\ApiTokenType;
use App\Enums\AuditAction;
use App\Enums\McpRevokeReason;
use App\Enums\SettingsArea;
use App\Models\ApiToken;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Mcp\McpAlerts;
use Illuminate\Support\Facades\DB;

/**
 * Take a user's MCP access away (plan 13 C8): every active MCP token of the
 * user is revoked with the reason and the grant is cleared, in one
 * transaction, with settings_audits rows and a management-chat alert.
 * Used by the Admin (Revoke access / Revoke all / Transfer), UserObserver
 * (deactivated, password changed) and the hourly sweep (role lost).
 * Query-builder writes: no model events, so the observer can't loop.
 * Returns the number of revoked tokens; a user with nothing to revoke is a
 * no-op (no audit, no alert).
 */
class RevokeMcpAccess
{
    public function handle(User $holder, McpRevokeReason $reason, ?User $by = null, ?string $ip = null): int
    {
        return DB::transaction(function () use ($holder, $reason, $by, $ip): int {
            $tokenIds = ApiToken::query()
                ->ofType(ApiTokenType::Mcp)
                ->where('user_id', $holder->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->pluck('id');

            if ($tokenIds->isNotEmpty()) {
                ApiToken::query()->whereKey($tokenIds)->update(['revoked_at' => now(), 'revoked_reason' => $reason->value]);
            }

            $hadGrant = User::query()->whereKey($holder->id)->whereNotNull('mcp_access_granted_at')
                ->update(['mcp_access_granted_at' => null, 'mcp_access_granted_by' => null]) > 0;

            $holder->forceFill(['mcp_access_granted_at' => null, 'mcp_access_granted_by' => null])
                ->syncOriginalAttributes(['mcp_access_granted_at', 'mcp_access_granted_by']);

            if ($tokenIds->isEmpty() && ! $hadGrant) {
                return 0;
            }

            foreach ($tokenIds as $id) {
                SettingsAudit::record(SettingsArea::Mcp, 'mcp_tokens.'.$id, AuditAction::Revoked, $by, $ip);
            }

            if ($hadGrant) {
                SettingsAudit::record(SettingsArea::Mcp, 'mcp_access.'.$holder->id, AuditAction::Revoked, $by, $ip);
            }

            McpAlerts::accessRevoked($holder, $reason, $by, $tokenIds->count());

            return $tokenIds->count();
        });
    }
}
