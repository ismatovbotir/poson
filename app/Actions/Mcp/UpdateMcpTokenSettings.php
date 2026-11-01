<?php

namespace App\Actions\Mcp;

use App\Enums\ApiTokenType;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\ApiToken;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Mcp\McpAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Change an active MCP token's idle auto-revoke days (14–180, empty = 45)
 * and IP allow-list (≤ 10 IPv4 / IPv6 addresses or CIDR ranges, empty =
 * any). Plan 13 C12/C14. Audited (key only) and alerted. Returns false when
 * nothing changed.
 *
 * @throws ValidationException
 */
class UpdateMcpTokenSettings
{
    /**
     * @param  array{idle_revoke_days?: mixed, allowed_ips?: mixed}  $data
     */
    public function handle(ApiToken $token, array $data, User $by, ?string $ip): bool
    {
        $values = GenerateMcpToken::validate($data, withLabel: false);

        $changed = DB::transaction(function () use ($token, $values, $by, $ip): bool {
            $locked = ApiToken::query()->whereKey($token->id)->ofType(ApiTokenType::Mcp)->whereNull('revoked_at')->lockForUpdate()->first();

            if ($locked === null) {
                throw ValidationException::withMessages(['idle_revoke_days' => __('mcp.errors.token_inactive')]);
            }

            $locked->fill([
                'idle_revoke_days' => $values['idle_revoke_days'],
                'allowed_ips' => $values['allowed_ips'] === [] ? null : $values['allowed_ips'],
            ]);

            if (! $locked->isDirty()) {
                return false;
            }

            $locked->save();

            SettingsAudit::record(SettingsArea::Mcp, 'mcp_tokens.'.$locked->id.'.settings', AuditAction::Updated, $by, $ip);

            return true;
        });

        if ($changed) {
            $token->refresh();
            McpAlerts::tokenSettingsChanged($token, $by);
        }

        return $changed;
    }
}
