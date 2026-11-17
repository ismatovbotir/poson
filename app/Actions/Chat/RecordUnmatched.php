<?php

namespace App\Actions\Chat;

use App\Enums\ChatChannel;
use App\Models\ChatUnmatched;
use App\Support\Chat\ChatRedactor;
use App\Support\Chat\ChatSubject;
use App\Support\Chat\ChatText;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Remember a phrase the bot did not understand, for the owner to teach it
 * (plan 28, CB-10). One row per channel, locale and normalized phrase with a
 * counter (upsert).
 *
 *  - Only subjects that may record phrases (ChatSubject::mayRecordPhrases,
 *    WC-2): registered Telegram users; website chats with consent, not
 *    banned, in bot mode and without an open lead form. The row's channel is
 *    the subject's (never hard-coded).
 *  - The text is redacted (ChatRedactor) BEFORE anything is stored: both
 *    "sample" and "normalized" come from the redacted text, so a phone, card,
 *    passport, email or link never reaches the table, and no chat or person
 *    is stored with the phrase.
 *  - Daily caps: per customer (chatbot.unmatched.per_day_chat) and overall
 *    (per_day_global); over a cap nothing is stored. Keys use
 *    chatLimiterKey() (Telegram's bare id: keys unchanged; web "web:{id}"),
 *    and the web has its own overall cap (per_day_global_web, key
 *    chatbot-unmatched:all:web:{day}), so it never uses up Telegram's (WC-1).
 *  - A phrase that is nothing but masks or shorter than 2 characters is dropped.
 *
 * Returns the id of the row (null when nothing was recorded). AnswerChatWithAi
 * also records every question the AI answered (plan 30 §16, AI-B15), under
 * the same caps, and links its assistant turn to that row, so the owner can
 * teach a rule from the answer.
 */
class RecordUnmatched
{
    public function handle(ChatSubject $user, string $locale, ?string $text): ?int
    {
        if (! $user->mayRecordPhrases() || ! in_array($locale, config('app.supported_locales'), true)) {
            return null;
        }

        $sample = ChatRedactor::clean($text);
        $normalized = ChatText::normalize($sample, 120);

        if (mb_strlen($normalized) < 2) {
            return null;
        }

        if (! $this->withinCaps($user)) {
            return null;
        }

        $key = ['channel' => $user->chatChannel()->value, 'locale' => $locale, 'normalized' => $normalized];
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

        $id = ChatUnmatched::query()->where($key)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Atomic: the hit itself decides, per customer first so one chatty
     * customer can't use up the overall cap.
     */
    private function withinCaps(ChatSubject $user): bool
    {
        $day = today()->format('Ymd');

        if (RateLimiter::hit("chatbot-unmatched:chat:{$day}:{$user->chatLimiterKey()}", 86400) > (int) config('chatbot.unmatched.per_day_chat', 20)) {
            return false;
        }

        if ($user->chatChannel() === ChatChannel::Web) {
            return RateLimiter::hit("chatbot-unmatched:all:web:{$day}", 86400) <= (int) config('chatbot.unmatched.per_day_global_web', 300);
        }

        return RateLimiter::hit("chatbot-unmatched:all:{$day}", 86400) <= (int) config('chatbot.unmatched.per_day_global', 1000);
    }
}
