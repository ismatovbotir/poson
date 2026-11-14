<?php

namespace App\Actions\Chat\Concerns;

use App\Actions\Chat\RecordChatStat;
use App\Actions\Telegram\RegisterTelegramUser;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Jobs\SendChatLookupFailureAlert;
use App\Models\TelegramUser;
use App\Support\Chat\ChatReply;
use App\Support\DateFormat;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The gate, limits and fixed replies shared by LookupOrderStatus and
 * LookupRepairStatus (plan 28 C3 / C3b, CB-15, CB-16, CB-28 to CB-30).
 *
 *  - Gate (no order or repair query when it fails, the bot offers a manager):
 *    a registered, not banned Telegram customer whose contact-share phone is
 *    a real number (never the anonymous +888) and who is the most recently
 *    registered conversation with that phone (a recycled number does not
 *    inherit the previous owner's history). The phone never comes from text.
 *  - Limits, hit before every query, every attempt counts: per customer
 *    chatbot.lookup.per_hour_user (5), over all chats per_hour_global (300).
 *  - A typed number that is not found counts per number for 24 h; at
 *    failures_alert (5) one queued counts-only monitoring alert (never the
 *    number or the phone), at most one per hour overall. The owner can still look it up: nothing is locked.
 *  - Nothing "verified" is remembered between messages.
 */
trait GuardsStatusLookup
{
    abstract protected function kind(): string;

    /**
     * The phone this customer may look up with, or null.
     */
    protected function verifiedPhone(TelegramUser $user): ?string
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

    /**
     * Atomic hits, per customer first (a customer over the limit does not use the global budget).
     */
    protected function allowLookup(TelegramUser $user): bool
    {
        if (RateLimiter::hit('chat-lookup:user:'.$user->id, 3600) > (int) config('chatbot.lookup.per_hour_user', 5)) {
            return false;
        }

        return RateLimiter::hit('chat-lookup:global', 3600) <= (int) config('chatbot.lookup.per_hour_global', 300);
    }

    protected function recordFailure(?string $number): void
    {
        app(RecordChatStat::class)->handle(ChatChannel::Telegram, ChatMetric::LookupFail);

        if ($number === null) {
            return;
        }

        $threshold = max(1, (int) config('chatbot.lookup.failures_alert', 5));
        $key = 'chat-lookup-fail:'.hash('sha256', $this->kind().'|'.$number);

        if (RateLimiter::hit($key, 86400) < $threshold) {
            return;
        }

        DB::afterCommit(function () use ($key, $threshold): void {
            if (Cache::add('chat-lookup-fail:alert', 1, 3600) && Cache::add($key.':alert', true, 86400)) {
                SendChatLookupFailureAlert::dispatch($threshold);
            }
        });
    }

    protected function recordSuccess(): void
    {
        app(RecordChatStat::class)->handle(ChatChannel::Telegram, ChatMetric::LookupOk);
    }

    /**
     * The gate refused: no query was made.
     */
    protected function managerOffer(string $locale): ChatReply
    {
        return new ChatReply(__('chatbot.lookup.unavailable', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE);
    }

    protected function limited(string $locale): ChatReply
    {
        return new ChatReply(__('chatbot.lookup.limited', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE);
    }

    /**
     * One reply for every miss: unknown number, another phone, a test or an old record.
     */
    protected function notFound(string $locale): ChatReply
    {
        return new ChatReply(__('chatbot.lookup.'.$this->kind().'.not_found', [], $locale), $this->closingButtons($locale), null, ChatReply::DONE);
    }

    /**
     * @return list<array{type: string, label: string}>
     */
    protected function closingButtons(string $locale): array
    {
        return [
            ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)],
            ['type' => 'menu', 'label' => __('chatbot.buttons.menu', [], $locale)],
        ];
    }

    /**
     * The day of the last status change in Tashkent ("12 November 2026"), or null.
     */
    protected function day(?CarbonInterface $at, string $locale): ?string
    {
        return $at === null ? null : DateFormat::iso($at->copy()->setTimezone('Asia/Tashkent'), 'D MMMM YYYY', $locale);
    }
}
