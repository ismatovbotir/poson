<?php

namespace App\Policies;

use App\Models\McpCall;
use App\Models\User;

/**
 * Settings → MCP access and the MCP call log (plan 13 §7, C28): mcp.manage,
 * seeded for the Admin role only (Admin also passes Gate::before). Every
 * write additionally needs a fresh password confirmation (in the component)
 * and re-checks its target in the action.
 */
class McpCallPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('mcp.manage');
    }

    public function view(User $user, McpCall $mcpCall): bool
    {
        return $user->can('mcp.manage');
    }

    /**
     * Grant / transfer / revoke access, generate / regenerate / revoke tokens.
     */
    public function manage(User $user): bool
    {
        return $user->can('mcp.manage');
    }
}
