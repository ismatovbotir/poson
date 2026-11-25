<?php

namespace App\Support\Telegram;

use App\Actions\Telegram\RegisterTelegramUser;
use App\Models\TelegramUser;
use App\Support\Phone;

/**
 * The one "verified phone" rule for a Telegram customer (plan 28 CB-15,
 * plan 20 MA-9): a registered, not banned user whose contact-share phone is
 * a real number (never the anonymous +888) and who is the most recently
 * registered conversation with that phone (a recycled number does not
 * inherit the previous owner's orders or history). The phone never comes
 * from typed text or a request field.
 *
 * Used by the chat bot status lookups (GuardsStatusLookup) and by every
 * Mini App checkout, quote and service request POST.
 */
final class VerifiedTelegramPhone
{
    /**
     * The normalized phone this customer may act with, or null.
     */
    public static function for(TelegramUser $user): ?string
    {
        if (! $user->isRegistered() || $user->isBanned() || RegisterTelegramUser::isAnonymousNumber($user->phone)) {
            return null;
        }

        $phone = Phone::normalizeAny($user->phone);

        if ($phone === null) {
            return null;
        }

        $newest = TelegramUser::query()
            ->where('phone', $phone)
            ->whereNotNull('registered_at')
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->value('id');

        return $newest === $user->id ? $phone : null;
    }
}
