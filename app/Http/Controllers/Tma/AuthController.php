<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Telegram\MiniApp\AuthenticateMiniAppUser;
use App\Http\Controllers\Controller;
use App\Support\Secrets;
use App\Support\Telegram\MiniApp\InitData;
use App\Support\Telegram\MiniApp\InvalidInitData;
use App\Support\Telegram\MiniApp\TmaTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /tma/auth (plan 20 §4): CSRF (PreventRequestForgery) and the per-IP
 * "tma-auth" throttle run first. Then:
 *
 *  - no bot token configured → 503 (fail closed, MA-13);
 *  - the initData is verified (InitData, MA-2); refused → 403 with the
 *    "expired" screen (nothing of the input is echoed, logged or flashed, MA-11);
 *  - only after the signature checked, the per-Telegram-id budget
 *    (AUTH_PER_USER per 10 minutes, MA-12);
 *  - AuthenticateMiniAppUser: a reason screen, or the user is signed in and
 *    the app goes to "next" (re-authentication) or the signed start_param,
 *    both TmaTarget only (MA-7).
 *
 * Any outcome but a sign-in invalidates the session and rotates the CSRF
 * token (a refused sign-in never leaves the previous account signed in).
 * The answer is JSON {"redirect": internal URL} for tma.js.
 */
class AuthController extends Controller
{
    public const AUTH_PER_USER = 20;

    public function __invoke(Request $request, AuthenticateMiniAppUser $authenticate): JsonResponse
    {
        $fallbackLocale = $request->getPreferredLanguage((array) config('app.supported_locales')) ?? (string) config('app.locale');
        $token = Secrets::get('telegram.bot_token');

        if (! is_string($token) || $token === '') {
            return $this->refuse($request, $fallbackLocale, 'disabled', 503);
        }

        $raw = $request->input('init_data');
        $data = is_string($raw) ? InitData::verify($raw, $token) : new InvalidInitData(InvalidInitData::MISSING);

        if ($data instanceof InvalidInitData) {
            return $this->refuse($request, $fallbackLocale, 'expired', 403);
        }

        $key = 'tma-auth-user:'.$data->telegramId;

        if (RateLimiter::hit($key, 600) > self::AUTH_PER_USER) {
            $this->forget($request);

            return response()->json(['redirect' => null, 'retry_after' => RateLimiter::availableIn($key)], 429);
        }

        $result = $authenticate->handle($data, $request->session());

        if (! $result->isSignedIn()) {
            return $this->refuse($request, $result->locale, (string) $result->reason, 200);
        }

        $next = $request->input('next');
        $target = TmaTarget::isValid($next) ? $next : $data->startParam;

        return response()->json(['redirect' => TmaTarget::url($target, $result->user, $result->locale)]);
    }

    /**
     * Every refused sign-in ends the session first, so a previous account's
     * Mini App session never outlives a failed or refused sign-in (MA-6).
     */
    private function refuse(Request $request, string $locale, string $reason, int $status): JsonResponse
    {
        $this->forget($request);

        return response()->json(['redirect' => route('tma.unavailable', ['locale' => $locale, 'reason' => $reason])], $status);
    }

    private function forget(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
