<?php

namespace App\Actions\Telegram\MiniApp;

use App\Enums\TelegramUserState;
use App\Models\TelegramUser;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use App\Support\Telegram\MiniApp\TmaSession;
use App\Support\Telegram\MiniApp\VerifiedInitData;
use Illuminate\Contracts\Session\Session;

/**
 * Sign a Telegram user into the Mini App from a verified initData (plan 20
 * §4, MA-6, MA-9, MA-13):
 *
 *  - the Mini App switch (DB setting AND the bot switch) off → "disabled";
 *  - no telegram_users row, not in the Registered state or registered_at
 *    empty → "register" (the screen links to the bot: /start tma);
 *  - banned → a neutral "banned" screen; blocked_at (the user blocked the
 *    bot) → "blocked" ("open the bot and press Start");
 *  - otherwise the session is invalidated and the user stored
 *    (TmaSession::start(): new id, new CSRF token, a different Telegram
 *    account never sees the previous one's state).
 *
 * Names, phone and customer link always come from the database, never from
 * initData. The locale is the user's (else the client's language, else uz).
 */
class AuthenticateMiniAppUser
{
    public function handle(VerifiedInitData $data, Session $session): MiniAppAuthResult
    {
        $user = TelegramUser::query()->where('telegram_id', $data->telegramId)->first();
        $locale = static::locale($user, $data);

        if (! MiniAppSwitch::isEnabled()) {
            return new MiniAppAuthResult($locale, reason: 'disabled');
        }

        if (! $user instanceof TelegramUser || $user->state !== TelegramUserState::Registered || ! $user->isRegistered()) {
            return new MiniAppAuthResult($locale, reason: 'register');
        }

        if ($user->isBanned()) {
            return new MiniAppAuthResult($locale, reason: 'banned');
        }

        if ($user->blocked_at !== null) {
            return new MiniAppAuthResult($locale, reason: 'blocked');
        }

        TmaSession::start($session, $user);

        return new MiniAppAuthResult($locale, user: $user);
    }

    public static function locale(?TelegramUser $user, ?VerifiedInitData $data = null): string
    {
        $supported = (array) config('app.supported_locales');

        foreach ([$user?->locale, $data?->languageCode] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return (string) config('app.locale', 'uz');
    }
}
