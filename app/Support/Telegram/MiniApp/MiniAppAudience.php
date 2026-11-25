<?php

namespace App\Support\Telegram\MiniApp;

use App\Enums\TelegramUserState;
use App\Models\TelegramUser;

/**
 * Who the bot offers the Mini App to (plan 20 M5, MA-14, MA-20, MA-33):
 * a registered customer (Registered state and registered_at), not banned,
 * not blocked, while MiniAppSwitch is on (the Mini App row AND the bot
 * switch, read fresh). The "Open catalog" button, the menu button and the
 * customer order status messages all use this one rule, checked when the
 * work is queued and again when the job runs.
 */
final class MiniAppAudience
{
    public static function includes(?TelegramUser $user): bool
    {
        return $user instanceof TelegramUser
            && $user->state === TelegramUserState::Registered
            && $user->isRegistered()
            && ! $user->isBanned()
            && $user->blocked_at === null
            && MiniAppSwitch::isEnabled();
    }
}
