<?php

namespace App\Http\Middleware;

use App\Support\Secrets;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram webhook authentication (plan 14 v2, condition 2): the
 * X-Telegram-Bot-Api-Secret-Token header must equal the webhook secret
 * from the Secrets resolver (hash_equals). 401 when no secret is
 * configured, the header is missing or it differs. The header is never
 * logged or echoed.
 *
 * Runs after LimitJsonBody and before ThrottleRequests (priority list in
 * bootstrap/app.php), so unauthenticated requests never use the budget.
 */
class VerifyTelegramSecret
{
    public const HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = Secrets::get('telegram.webhook_secret');
        $given = $request->headers->get(self::HEADER);

        if (! is_string($secret) || $secret === '' || ! is_string($given) || $given === '' || ! hash_equals($secret, $given)) {
            return response()->json(['ok' => false], 401);
        }

        return $next($request);
    }
}
