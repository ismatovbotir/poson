<?php

namespace App\Http\Middleware;

use App\Actions\Mcp\RecordMcpCall;
use App\Enums\ApiTokenType;
use App\Enums\McpCallStatus;
use App\Enums\McpDenyReason;
use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\McpCall;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request MCP holder check (plan 13 C7, scope update 2026-11-01), after
 * AuthenticateMcpToken. The request passes only when:
 * - the token is an active MCP token of this very user;
 * - the user is active and has the Admin role itself (not via Gate::before);
 * - the user holds MCP access (mcp_access_granted_at) and is the only holder.
 * Otherwise 403 and a denied mcp_calls row with the reason. On success the
 * locale is en (D8). Catches what the observer can't: mass updates and role
 * changes made with spatie events off.
 */
class EnsureMcpUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get(AuthenticateMcpToken::ATTRIBUTE);
        $user = $request->user();

        $reason = match (true) {
            ! $token instanceof ApiToken || $token->type !== ApiTokenType::Mcp || ! $token->isUsable() => McpDenyReason::Token,
            ! $user instanceof User || $token->user_id !== $user->id => McpDenyReason::TokenUser,
            ! $user->is_active => McpDenyReason::Inactive,
            ! $user->hasRole(Role::Admin) => McpDenyReason::NotAdmin,
            $user->mcp_access_granted_at === null => McpDenyReason::NoAccess,
            User::query()->whereNotNull('mcp_access_granted_at')->whereKeyNot($user->id)->exists() => McpDenyReason::NotSingleHolder,
            default => null,
        };

        if ($reason !== null) {
            app(RecordMcpCall::class)->handle(
                $user instanceof User ? $user : null,
                $token instanceof ApiToken ? $token : null,
                McpCall::REQUEST_TOOL,
                McpCallStatus::Denied,
                $reason->arguments(),
                request: $request,
            );

            return response()->json([
                'error' => 'forbidden',
                'message' => 'MCP access is not allowed for this account.',
            ], 403);
        }

        app()->setLocale('en');

        return $next($request);
    }
}
