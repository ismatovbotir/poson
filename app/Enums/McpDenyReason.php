<?php

namespace App\Enums;

/**
 * Why an MCP request was refused (mcp_calls.arguments.reason of denied rows,
 * plan 13 C7/C11/C14). Written by AuthenticateMcpToken and EnsureMcpUser.
 */
enum McpDenyReason: string
{
    /** A revoked token was presented. */
    case RevokedToken = 'revoked_token';
    /** The token's IP allow-list doesn't include the address. */
    case IpNotAllowed = 'ip_not_allowed';
    /** No usable MCP token on the request. */
    case Token = 'token';
    /** The token belongs to another user. */
    case TokenUser = 'token_user';
    case Inactive = 'inactive';
    case NotAdmin = 'not_admin';
    case NoAccess = 'no_access';
    case NotSingleHolder = 'not_single_holder';

    public function label(): string
    {
        return __("enums.mcp_deny_reason.{$this->value}");
    }

    /**
     * Arguments stored on the denied mcp_calls row.
     *
     * @return array{reason: string}
     */
    public function arguments(): array
    {
        return ['reason' => $this->value];
    }
}
