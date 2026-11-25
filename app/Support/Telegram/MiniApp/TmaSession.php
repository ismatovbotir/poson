<?php

namespace App\Support\Telegram\MiniApp;

use App\Enums\TelegramUserState;
use App\Models\TelegramUser;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * The Mini App session state (plan 20 §4, MA-6): only the user's row id,
 * Telegram id, sign-in time and last activity, in the separate
 * "poson_tma_session" session (ConfigureTmaSession). No guard and no
 * Auth::login(); initData is never stored (MA-11).
 *
 * start(): the session is invalidated (flushed, new id) and the CSRF token
 * rotated before the user is stored, so a fixed or shared session never
 * carries over, and a different Telegram account starts from nothing.
 */
final class TmaSession
{
    public const USER = 'tma.uid';

    public const TELEGRAM_ID = 'tma.tg_id';

    public const AUTH_AT = 'tma.auth_at';

    public const SEEN_AT = 'tma.seen_at';

    /**
     * The request attribute holding the TelegramUser after EnsureTmaUser.
     */
    public const ATTRIBUTE = 'tma.user';

    public static function start(Session $session, TelegramUser $user): void
    {
        $session->invalidate();
        $session->regenerateToken();

        $now = now()->getTimestamp();
        $session->put([
            self::USER => $user->id,
            self::TELEGRAM_ID => $user->telegram_id,
            self::AUTH_AT => $now,
            self::SEEN_AT => $now,
        ]);
    }

    /**
     * Whether the session's sign-in is still valid by the clock: idle and absolute lifetime.
     */
    public static function isFresh(Session $session): bool
    {
        $authAt = $session->get(self::AUTH_AT);
        $seenAt = $session->get(self::SEEN_AT);
        $now = now()->getTimestamp();

        if (! is_int($authAt) || ! is_int($seenAt)) {
            return false;
        }

        return $now - $seenAt <= (int) config('telegram.mini_app.idle_minutes', 120) * 60
            && $now - $authAt <= (int) config('telegram.mini_app.absolute_minutes', 720) * 60;
    }

    /**
     * The signed-in user of the request (set by EnsureTmaUser).
     */
    public static function user(Request $request): TelegramUser
    {
        $user = $request->attributes->get(self::ATTRIBUTE);

        abort_unless($user instanceof TelegramUser, 403);

        return $user;
    }

    /**
     * May use the Mini App now: registered, not banned, not blocked (MA-13: the switch is checked separately).
     */
    public static function isEligible(TelegramUser $user): bool
    {
        return $user->state === TelegramUserState::Registered
            && $user->isRegistered()
            && ! $user->isBanned()
            && $user->blocked_at === null;
    }

    /**
     * Per-user limiter key: the session's user row id, else the IP.
     */
    public static function limiterKey(Request $request): string
    {
        $userId = $request->hasSession() ? $request->session()->get(self::USER) : null;

        return is_int($userId) ? 'u'.$userId : 'ip'.sha1((string) $request->ip());
    }
}
