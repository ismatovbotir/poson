<?php

namespace App\Actions\Mcp;

use App\Enums\AuditAction;
use App\Enums\McpRevokeReason;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Mcp\McpAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Make a user the MCP holder (plan 13, scope update 2026-11-01: exactly one
 * holder, the CEO). The target must be an active user with the Admin role
 * itself (C28). Runs under a lock on the current holder rows:
 * - nobody holds access → granted;
 * - someone else holds it → refused unless $transfer, which first revokes
 *   the previous holder's tokens and grant (RevokeMcpAccess, reason
 *   transferred), so their tokens stop working at once.
 * Audited (settings_audits, area mcp) and alerted. Returns false when the
 * target already was the only holder.
 *
 * @throws ValidationException
 */
class GrantMcpAccess
{
    public function __construct(protected RevokeMcpAccess $revokeAccess) {}

    public function handle(User $target, User $by, ?string $ip, bool $transfer = false): bool
    {
        return DB::transaction(function () use ($target, $by, $ip, $transfer): bool {
            $holders = User::query()->whereNotNull('mcp_access_granted_at')->lockForUpdate()->get();
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if (! $target->isActiveAdmin()) {
                throw ValidationException::withMessages(['user' => __('mcp.errors.target_not_admin')]);
            }

            $others = $holders->reject(fn (User $holder): bool => $holder->is($target));

            if ($others->isEmpty() && $target->hasMcpAccess()) {
                return false;
            }

            if ($others->isNotEmpty() && ! $transfer) {
                throw ValidationException::withMessages(['user' => __('mcp.errors.holder_exists', ['name' => $others->first()->name])]);
            }

            foreach ($others as $previous) {
                $this->revokeAccess->handle($previous, McpRevokeReason::Transferred, $by, $ip);
            }

            User::query()->whereKey($target->id)->update(['mcp_access_granted_at' => now(), 'mcp_access_granted_by' => $by->id]);

            SettingsAudit::record(SettingsArea::Mcp, 'mcp_access.'.$target->id, $others->isNotEmpty() ? AuditAction::Transferred : AuditAction::Granted, $by, $ip);

            McpAlerts::accessGranted($target, $by, $others->first());

            return true;
        });
    }
}
