<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * No other site may frame the site, the admin panel or the customer links
 * (/o, /q, /r, PDFs): plan 20 MA-4, required because the Telegram Mini App
 * shares this origin. Runs on the whole "web" group (the Mini App's "tma"
 * group is separate and lets only Telegram Web frame it). Sends
 * X-Frame-Options: DENY, and "frame-ancestors 'none'" as the CSP unless the
 * response already has its own policy (the CV download keeps its sandbox
 * policy unchanged; DENY still applies).
 */
class DenyFraming
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        }

        return $response;
    }
}
