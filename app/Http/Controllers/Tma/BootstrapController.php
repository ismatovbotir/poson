<?php

namespace App\Http\Controllers\Tma;

use App\Http\Controllers\Controller;
use App\Support\Telegram\MiniApp\TmaTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /tma (plan 20 §3): the entry page Telegram opens. tma.js posts the
 * signed initData with the CSRF token to /tma/auth and follows the returned
 * internal URL. "next" (after a session expiry) is passed on only when it
 * is a TmaTarget (MA-7). Outside Telegram (no initData) tma.js shows the
 * "open in Telegram" screen. The language is guessed from the browser until
 * the user is known.
 */
class BootstrapController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $next = $request->query('next');
        $locale = $request->getPreferredLanguage((array) config('app.supported_locales')) ?? (string) config('app.locale');
        app()->setLocale($locale);

        return response()->view('tma.bootstrap', [
            'next' => TmaTarget::isValid($next) ? $next : null,
            'locale' => $locale,
        ]);
    }
}
