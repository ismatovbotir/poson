<?php

namespace App\Enums;

/**
 * How an MCP request was authenticated (mcp_calls.auth_type, plan 13 §4).
 * Static "posm_" tokens only (decision 2026-11-01: no OAuth).
 */
enum McpAuthType: string
{
    case Token = 'token';

    public function label(): string
    {
        return __("enums.mcp_auth_type.{$this->value}");
    }
}
