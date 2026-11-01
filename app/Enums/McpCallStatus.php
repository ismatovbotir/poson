<?php

namespace App\Enums;

/**
 * Outcome of one MCP request (mcp_calls.status, plan 13 §4): ok; invalid
 * arguments; denied (no permission, access revoked, revoked token used,
 * IP not allowed); rate_limited; error (unexpected failure, details only
 * in the server log).
 */
enum McpCallStatus: string
{
    case Ok = 'ok';
    case Invalid = 'invalid';
    case Denied = 'denied';
    case RateLimited = 'rate_limited';
    case Error = 'error';

    public function label(): string
    {
        return __("enums.mcp_call_status.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'brand',
            self::Invalid => 'amber',
            self::Denied, self::Error => 'red',
            self::RateLimited => 'muted',
        };
    }
}
