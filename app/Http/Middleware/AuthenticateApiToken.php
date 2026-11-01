<?php

namespace App\Http\Middleware;

use App\Enums\ApiTokenType;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token check of the machine API (plan 09), alias "api.token:<ability>".
 *
 * - The per-IP failure limiter (MAX_FAILURES per minute) is checked first,
 *   before any hashing or lookup: over it → 429 too_many_failed_attempts.
 * - A missing, malformed, unknown, revoked or expired token, or one without
 *   the ability → 401 {"error": "unauthenticated"} and a failure hit.
 * - On success the token is in $request->attributes ("api_token");
 *   last_used_at / last_used_ip are written at most once per minute.
 *
 * Only price API tokens ("pos_", type price_api) pass: an MCP token never
 * authenticates here, whatever its abilities (plan 13, C5).
 *
 * Never logs or echoes the token or the Authorization header.
 */
class AuthenticateApiToken
{
    public const MAX_FAILURES = 10;

    public const DECAY_SECONDS = 60;

    public const ATTRIBUTE = 'api_token';

    public const LAST_USED_EVERY_SECONDS = 60;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $ability = ApiToken::ABILITY_PRICES_WRITE): Response
    {
        $limiterKey = static::failureKey((string) $request->ip());

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_FAILURES)) {
            $seconds = RateLimiter::availableIn($limiterKey);

            return response()->json([
                'error' => 'too_many_failed_attempts',
                'message' => 'Too many failed authentication attempts. Retry later.',
            ], 429, ['Retry-After' => (string) $seconds]);
        }

        $token = $this->resolve((string) $request->bearerToken(), $ability);

        if ($token === null) {
            RateLimiter::hit($limiterKey, self::DECAY_SECONDS);

            return static::unauthenticated();
        }

        $this->touch($token, $request);

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $next($request);
    }

    public static function failureKey(string $ip): string
    {
        return 'api-token-failed:'.$ip;
    }

    public static function unauthenticated(): JsonResponse
    {
        return response()->json([
            'error' => 'unauthenticated',
            'message' => 'A valid API token is required.',
        ], 401, ['WWW-Authenticate' => 'Bearer']);
    }

    private function resolve(#[\SensitiveParameter] string $plain, string $ability): ?ApiToken
    {
        if (! ApiToken::looksValid($plain, ApiTokenType::PriceApi)) {
            return null;
        }

        $token = ApiToken::findByPlainText($plain, ApiTokenType::PriceApi);

        if ($token === null || $token->type !== ApiTokenType::PriceApi || ! $token->isUsable() || ! $token->hasAbility($ability)) {
            return null;
        }

        return $token;
    }

    /**
     * last_used_at / last_used_ip, at most once per LAST_USED_EVERY_SECONDS (no model events, no updated_at).
     */
    private function touch(ApiToken $token, Request $request): void
    {
        if ($token->last_used_at !== null && $token->last_used_at->gt(now()->subSeconds(self::LAST_USED_EVERY_SECONDS))) {
            return;
        }

        $now = now();
        $ip = $request->ip() !== null ? mb_substr((string) $request->ip(), 0, 45) : null;

        DB::table('api_tokens')->where('id', $token->id)->update(['last_used_at' => $now, 'last_used_ip' => $ip]);

        $token->setRawAttributes(array_merge($token->getAttributes(), ['last_used_at' => $now, 'last_used_ip' => $ip]), true);
    }
}
