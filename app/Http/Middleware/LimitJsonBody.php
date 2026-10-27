<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses request bodies over MAX_BYTES (256 KB) with 413
 * {"error": "payload_too_large"} before authentication or decoding
 * (plan 09). Checks the declared Content-Length and the actual body.
 */
class LimitJsonBody
{
    public const MAX_BYTES = 262_144;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $declared = $request->headers->get('Content-Length');

        if ((is_numeric($declared) && (int) $declared > self::MAX_BYTES) || strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json([
                'error' => 'payload_too_large',
                'message' => 'The request body is larger than 256 KB.',
            ], 413);
        }

        return $next($request);
    }
}
