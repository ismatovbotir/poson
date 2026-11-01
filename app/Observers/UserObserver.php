<?php

namespace App\Observers;

use App\Actions\Mcp\RevokeMcpAccess;
use App\Enums\McpRevokeReason;
use App\Models\User;

/**
 * Plan 13 C8: a user who is deactivated or changes the password loses MCP
 * access at once (all MCP tokens revoked, grant cleared, alerted). Role loss
 * is caught per request by EnsureMcpUser and revoked by the hourly
 * `mcp:sweep` (spatie role events are off).
 */
class UserObserver
{
    public function updated(User $user): void
    {
        $reason = match (true) {
            $user->wasChanged('is_active') && ! $user->is_active => McpRevokeReason::Deactivated,
            $user->wasChanged('password') => McpRevokeReason::PasswordChanged,
            default => null,
        };

        if ($reason !== null) {
            app(RevokeMcpAccess::class)->handle($user, $reason, auth()->user() instanceof User ? auth()->user() : null, request()->ip());
        }
    }
}
