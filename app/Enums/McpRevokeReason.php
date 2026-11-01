<?php

namespace App\Enums;

/**
 * Why an MCP token was revoked (api_tokens.revoked_reason, plan 13 C8/C12).
 */
enum McpRevokeReason: string
{
    /** Revoked by an Admin on Settings → MCP access (one token or the holder's access). */
    case Admin = 'admin';
    /** "Revoke all MCP access". */
    case RevokeAll = 'revoke_all';
    /** A new token was generated for the same label. */
    case Regenerated = 'regenerated';
    /** Access was transferred to another user. */
    case Transferred = 'transferred';
    case Deactivated = 'deactivated';
    case PasswordChanged = 'password_changed';
    /** The holder lost the Admin role. */
    case RoleLost = 'role_lost';
    /** Unused for its idle_revoke_days. */
    case Idle = 'idle';
    /** Never used within 7 days of being generated. */
    case NeverUsed = 'never_used';

    public function label(): string
    {
        return __("enums.mcp_revoke_reason.{$this->value}");
    }
}
