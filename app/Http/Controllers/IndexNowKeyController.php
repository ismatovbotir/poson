<?php

namespace App\Http\Controllers;

use App\Support\Site\IndexNow;
use Illuminate\Http\Response;

/**
 * GET /{key}.txt (plan 18): the IndexNow key file, which the search engines
 * fetch to verify the key. Answers only while IndexNow is active and the
 * path is exactly the key; every other case is the same plain 404 (disabled,
 * wrong key, wrong length), so the URL says nothing about the state. Runs
 * without session and cookie middleware (see routes/web.php), so the 200 is
 * revalidated on every request (key rotation or disabling applies at once).
 */
class IndexNowKeyController extends Controller
{
    public function __invoke(string $indexnowKey): Response
    {
        $key = IndexNow::isActive() ? IndexNow::key() : null;

        if ($key === null || ! hash_equals($key, $indexnowKey)) {
            return response('Not Found', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response($key, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
