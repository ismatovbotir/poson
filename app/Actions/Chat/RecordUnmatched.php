<?php

namespace App\Actions\Chat;

use App\Enums\ChatChannel;
use App\Models\ChatUnmatched;
use App\Models\TelegramUser;
use App\Support\Chat\ChatRedactor;
use App\Support\Chat\ChatText;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Remember a phrase the bot did not understand, for the owner to teach it
 * (plan 28, CB-10). One row per channel, locale and normalized phrase with a
 * counter (upsert).
 *
 *  - Only registered Telegram users are recorded.
 *  - The text is redacted (ChatRedactor) BEFORE anything is stored: both
 *    "sample" and "normalized" come from the redacted text, so a phone, card,
 *    passport, email or link never reaches the table, and no chat or person
 *    is stored with the phrase.
 *  - Daily caps: per customer (chatbot.unmatched.per_day_chat) and overall
 *    (per_day_global); over a cap nothing is stored.
 *  - A phrase that is nothing but masks or shorter than 2 characters is dropped.
 */
class RecordUnmatched
{
    public function handle(TelegramUser $user, string $locale, ?string $text): bool
    {
        if (! $user->isRegistered() || ! in_array($locale, config('app.supported_locales'), true)) {
            return false;
        }

        $sample = ChatRedactor::clean($text);
        $normalized = ChatText::normalize($sample, 120);

        if (mb_strlen($normalized) < 2) {
            return false;
        }

        if (! $this->withinCaps($user)) {
            return false;
        }

        $key = ['channel' => ChatChannel::Telegram->value, 'locale' => $locale, 'normalized' => $normalized];
        $now = now();

        $inserted = ChatUnmatched::query()->insertOrIgnore([
            ...$key,
            'sample' => $sample,
            'count' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            ChatUnmatched::query()->where($key)->increment('count', 1, ['last_seen_at' => $now, 'updated_at' => $now]);
        }

        return true;
    }

    /**
     * Atomic: the hit itself decides, per customer first so one chatty
     * customer can't use up the overall cap.
     */
    private function withinCaps(TelegramUser $user): bool
    {
        $day = today()->format('Ymd');

        if (RateLimiter::hit("chatbot-unmatched:chat:{$day}:{$user->id}", 86400) > (int) config('chatbot.unmatched.per_day_chat', 20)) {
            return false;
        }

        return RateLimiter::hit("chatbot-unmatched:all:{$day}", 86400) <= (int) config('chatbot.unmatched.per_day_global', 1000);
    }
}
