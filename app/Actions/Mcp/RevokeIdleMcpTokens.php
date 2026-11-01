<?php

namespace App\Actions\Mcp;

use App\Enums\ApiTokenType;
use App\Enums\McpRevokeReason;
use App\Models\ApiToken;
use App\Support\Mcp\McpAlerts;
use Illuminate\Support\Facades\Cache;

/**
 * Daily MCP token hygiene (plan 13 C12/C13, decision 2026-11-01), run by
 * `mcp:revoke-idle`:
 * - never used within NEVER_USED_REVOKE_DAYS (7) of generation → revoked
 *   (reason never_used);
 * - unused for idle_revoke_days (default 45, 14–180 per token) → revoked
 *   (reason idle);
 * - older than ROTATE_AFTER_DAYS (180) → a "rotate" reminder at most once
 *   per REMIND_EVERY_DAYS (30). No forced expiry.
 * Each revoke is audited and alerted (RevokeMcpToken).
 */
class RevokeIdleMcpTokens
{
    public const REMIND_EVERY_DAYS = 30;

    public function __construct(protected RevokeMcpToken $revokeToken) {}

    /**
     * @return array{never_used: int, idle: int, reminded: int}
     */
    public function handle(): array
    {
        $counts = ['never_used' => 0, 'idle' => 0, 'reminded' => 0];

        $tokens = ApiToken::query()->ofType(ApiTokenType::Mcp)->whereNull('revoked_at')->with('user')->get();

        foreach ($tokens as $token) {
            $reason = match (true) {
                $token->last_used_at === null && $token->created_at->lte(now()->subDays(ApiToken::NEVER_USED_REVOKE_DAYS)) => McpRevokeReason::NeverUsed,
                $token->last_used_at !== null && $token->last_used_at->lte(now()->subDays($token->idleRevokeDays())) => McpRevokeReason::Idle,
                default => null,
            };

            if ($reason !== null) {
                if ($this->revokeToken->handle($token, $reason)) {
                    $counts[$reason === McpRevokeReason::Idle ? 'idle' : 'never_used']++;
                }

                continue;
            }

            if ($token->needsRotation() && Cache::add('mcp-rotation-reminder:'.$token->id, true, now()->addDays(self::REMIND_EVERY_DAYS))) {
                McpAlerts::rotationDue($token);
                $counts['reminded']++;
            }
        }

        return $counts;
    }
}
