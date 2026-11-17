<?php

namespace App\Support\Chat;

use App\Enums\ChatMode;
use App\Models\WebChat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Counts for the monitoring row and alert (plan 28 C5d, CB-27, WC-13):
 * numbers only, never a name, phone or text.
 */
final class WebChatBacklog
{
    /**
     * Handed-off, open, not banned, not expired chats whose visitor has waited
     * longer than $minutes with no staff reply after their last message
     * (waiting since coalesce(last_inbound_at, handoff_at)).
     */
    public static function unanswered(?int $minutes = null): int
    {
        $minutes ??= max(1, (int) config('chatbot.web.unanswered_minutes', 15));
        $since = 'coalesce(web_chats.last_inbound_at, web_chats.handoff_at)';

        return WebChat::query()
            ->where('mode', ChatMode::Manager)
            ->whereNotNull('handoff_at')
            ->whereNull('closed_at')
            ->whereNull('banned_at')
            ->where('last_message_at', '>=', now()->subHours(max(1, (int) config('chatbot.web.expire_hours', 24))))
            ->whereRaw("{$since} < ?", [now()->subMinutes($minutes)])
            ->where(fn (Builder $q) => $q->whereNull('last_staff_reply_at')->orWhereRaw("web_chats.last_staff_reply_at < {$since}"))
            ->count();
    }

    /**
     * The highest number of new chats from one IP hash today (abuse signal, W-4).
     */
    public static function busiestIpToday(): int
    {
        return (int) WebChat::query()->toBase()
            ->whereNotNull('ip_hash')
            ->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('count(*) as total')
            ->groupBy('ip_hash')
            ->orderByDesc('total')
            ->limit(1)
            ->value('total');
    }
}
