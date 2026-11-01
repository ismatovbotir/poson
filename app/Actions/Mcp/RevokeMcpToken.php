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
 * Revoke one MCP token (plan 13 C9/C12): Admin "Revoke" on a device,
 * regenerate (reason regenerated) and the idle auto-revoke. The grant stays.
 * MCP tokens only; revoking twice is a no-op (false). Audited and alerted.
 */
class RevokeMcpToken
{
    public function handle(ApiToken $token, McpRevokeReason $reason, ?User $by = null, ?string $ip = null, bool $alert = true): bool
    {
        $revoked = DB::transaction(function () use ($token, $reason, $by, $ip): bool {
            $revoked = ApiToken::query()->whereKey($token->id)->ofType(ApiTokenType::Mcp)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => $reason->value]) > 0;

            if ($revoked) {
                SettingsAudit::record(SettingsArea::Mcp, 'mcp_tokens.'.$token->id, AuditAction::Revoked, $by, $ip);
            }

            return $revoked;
        });

        $token->refresh();

        if ($revoked && $alert) {
            McpAlerts::tokenRevoked($token, $reason, $by);
        }

        return $revoked;
    }
}
