<?php

namespace App\Support\Mcp;

use App\Mcp\Support\UntrustedText;

/**
 * The "family" of an MCP client from its User-Agent (plan 13 C10): the
 * first product token, lower-cased ("node", "claude-code", "python-httpx"),
 * so a new kind of client alerts but a version bump doesn't.
 */
final class ClientFamily
{
    public static function of(?string $userAgent): string
    {
        if ($userAgent !== null && preg_match('/^\s*([A-Za-z][A-Za-z0-9._\-]{0,39})/', $userAgent, $matches)) {
            return strtolower($matches[1]);
        }

        return 'unknown';
    }

    /**
     * Short, cleaned User-Agent for security alerts (never the full header).
     */
    public static function short(?string $userAgent): string
    {
        return UntrustedText::clean($userAgent, 60) ?? 'unknown';
    }
}
