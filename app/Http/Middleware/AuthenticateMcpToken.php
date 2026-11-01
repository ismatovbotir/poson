<?php

namespace App\Http\Middleware;

use App\Actions\Mcp\RecordMcpCall;
use App\Enums\ApiTokenType;
use App\Enums\McpCallStatus;
use App\Enums\McpDenyReason;
use App\Models\ApiToken;
use App\Models\McpCall;
use App\Support\Mcp\IpAllowList;
use App\Support\Mcp\McpTokenWatcher;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token check of the MCP endpoint (plan 13 C4–C6, C10, C11, C14),
 * alias "mcp.auth". Only "posm_" tokens of type mcp pass (a price API
 * "pos_" token never does, C5); there is no cookie / session auth.
 *
 * - The per-IP failure limiter of the price API (AuthenticateApiToken,
 *   10 per minute) is checked first, before any hashing: over it → 429.
 * - Missing, malformed or unknown token → 401 + a failure hit (nothing else).
 * - Revoked token → 401 + failure hit + a denied mcp_calls row + an alert
 *   (≤ 1 per hour per token).
 * - Address outside the token's allow-list → 401 + failure hit + denied
 *   row + alert (≤ 1 per hour per token).
 * - Otherwise: first-use / new IP / new client alerts (McpTokenWatcher),
 *   last_used_at / ip / ua written at most once per minute, the token in
 *   $request->attributes ("mcp_token") and the holder as the request user.
 *   EnsureMcpUser then checks the user.
 *
 * Never logs or echoes the token or the Authorization header.
 */
class AuthenticateMcpToken
{
    public const ATTRIBUTE = 'mcp_token';

    public function handle(Request $request, Closure $next): Response
    {
        $limiterKey = AuthenticateApiToken::failureKey((string) $request->ip());

        if (RateLimiter::tooManyAttempts($limiterKey, AuthenticateApiToken::MAX_FAILURES)) {
            return response()->json([
                'error' => 'too_many_failed_attempts',
                'message' => 'Too many failed authentication attempts. Retry later.',
            ], 429, ['Retry-After' => (string) RateLimiter::availableIn($limiterKey)]);
        }

        $token = $this->find((string) $request->bearerToken());

        if ($token === null || ! $token->hasAbility(ApiToken::ABILITY_MCP) || $token->user_id === null) {
            RateLimiter::hit($limiterKey, AuthenticateApiToken::DECAY_SECONDS);

            return static::unauthenticated();
        }

        if (! $token->isUsable()) {
            RateLimiter::hit($limiterKey, AuthenticateApiToken::DECAY_SECONDS);
            $this->deny($token, $request, McpDenyReason::RevokedToken);
            McpTokenWatcher::revokedUsed($token, $request);

            return static::unauthenticated();
        }

        if (! IpAllowList::allows($token->allowed_ips, $request->ip())) {
            RateLimiter::hit($limiterKey, AuthenticateApiToken::DECAY_SECONDS);
            $this->deny($token, $request, McpDenyReason::IpNotAllowed);
            McpTokenWatcher::ipBlocked($token, $request);

            return static::unauthenticated();
        }

        $user = $token->user;

        if ($user === null) {
            RateLimiter::hit($limiterKey, AuthenticateApiToken::DECAY_SECONDS);

            return static::unauthenticated();
        }

        McpTokenWatcher::observe($token, $request);
        $this->touch($token, $request);

        $request->attributes->set(self::ATTRIBUTE, $token);
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }

    public static function unauthenticated(): JsonResponse
    {
        return response()->json([
            'error' => 'unauthenticated',
            'message' => 'A valid MCP token is required.',
        ], 401, ['WWW-Authenticate' => 'Bearer']);
    }

    private function find(#[\SensitiveParameter] string $plain): ?ApiToken
    {
        if (! ApiToken::looksValid($plain, ApiTokenType::Mcp)) {
            return null;
        }

        $token = ApiToken::findByPlainText($plain, ApiTokenType::Mcp);

        return $token?->type === ApiTokenType::Mcp ? $token : null;
    }

    private function deny(ApiToken $token, Request $request, McpDenyReason $reason): void
    {
        $token->loadMissing('user');

        app(RecordMcpCall::class)->handle($token->user, $token, McpCall::REQUEST_TOOL, McpCallStatus::Denied, $reason->arguments(), request: $request);
    }

    /**
     * last_used_at / ip / ua, at most once per minute (no model events, no updated_at).
     */
    private function touch(ApiToken $token, Request $request): void
    {
        if ($token->last_used_at !== null && $token->last_used_at->gt(now()->subSeconds(AuthenticateApiToken::LAST_USED_EVERY_SECONDS))) {
            return;
        }

        $values = [
            'last_used_at' => now(),
            'last_used_ip' => $request->ip() !== null ? mb_substr((string) $request->ip(), 0, 45) : null,
            'last_used_ua' => $request->userAgent() !== null ? mb_substr($request->userAgent(), 0, 255) : null,
        ];

        DB::table('api_tokens')->where('id', $token->id)->update($values);

        $token->setRawAttributes(array_merge($token->getAttributes(), $values), true);
    }
}
