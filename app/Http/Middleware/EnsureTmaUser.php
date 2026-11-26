<?php

namespace App\Http\Middleware;

use App\Models\TelegramUser;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use App\Support\Telegram\MiniApp\TmaSession;
use App\Support\Telegram\MiniApp\TmaTarget;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every signed-in Mini App request (plan 20 §4, MA-6, MA-13): the user is
 * re-read from the database and re-checked each time (the switch, still
 * registered, not banned, not blocked, the same Telegram id) and the
 * session must be within its idle and absolute lifetime (server-side). Any
 * failure invalidates the session and sends the app back to /tma, which
 * signs in again from Telegram's initData (and shows the reason screen
 * when it fails); "next" is the current page as a TmaTarget only (MA-7).
 * The user is passed on as the request attribute TmaSession::ATTRIBUTE.
 * A request that wants JSON (the cart forms of tma.js) gets a 401 JSON
 * instead, and the script reloads the page, which signs in again.
 */
class EnsureTmaUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $userId = $session->get(TmaSession::USER);
        $user = is_int($userId) ? TelegramUser::query()->find($userId) : null;

        $valid = $user instanceof TelegramUser
            && $user->telegram_id === $session->get(TmaSession::TELEGRAM_ID)
            && TmaSession::isFresh($session)
            && TmaSession::isEligible($user)
            && MiniAppSwitch::isEnabled();

        if (! $valid) {
            $session->invalidate();

            // tma.js (plan 45, MA-43): a 401 it can tell from a page, never a redirect fetch() would follow.
            if ($request->wantsJson()) {
                return response()->json(['message' => __('tma.session.expired')], 401);
            }

            return redirect()->route('tma.bootstrap', array_filter(['next' => TmaTarget::forRoute($request->route())]));
        }

        $session->put(TmaSession::SEEN_AT, now()->getTimestamp());
        $request->attributes->set(TmaSession::ATTRIBUTE, $user);

        return $next($request);
    }
}
