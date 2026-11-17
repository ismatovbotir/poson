<?php

namespace App\Actions\Chat\Web;

use App\Enums\ChatMode;
use App\Enums\ConversationStatus;
use App\Models\WebChat;
use App\Support\Chat\WebChatVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Nightly retention of website chats (plan 28 C5d, CB-25, WC-14, WC-15).
 * Ages come from coalesce(last_message_at, created_at); ip_hash from
 * created_at; banned chats from banned_at. Steps:
 *
 *  1. expire: open, not banned chats idle longer than chatbot.web.expire_hours
 *     are closed (back to the bot, dialog cleared, new AI context), version
 *     bumped; nothing is deleted;
 *  2. delete bot-only chats (no hand-off, no lead) after 30 days;
 *  3. delete handed-off chats without a lead after 90 days;
 *  4. delete lead-linked chats after 12 months;
 *  5. null ip_hash after 30 days;
 *  6. banned chats: 30 days after banned_at messages are deleted and name,
 *     phone, first_page and dialog nulled (purged_at set); 12 months after
 *     banned_at the row goes.
 * Steps 2 to 4 never touch a banned row (the ban must keep blocking until
 * step 6 removes it). Deletes cascade to messages and AI turns by foreign
 * key. Idempotent; the log holds counts only.
 */
class PruneWebChats
{
    public const BOT_ONLY_DAYS = 30;

    public const HANDED_OFF_DAYS = 90;

    public const LEAD_MONTHS = 12;

    public const IP_HASH_DAYS = 30;

    public const BAN_PURGE_DAYS = 30;

    public const BAN_DELETE_MONTHS = 12;

    /**
     * @return array{expired: int, deleted: int, ip_cleared: int, purged: int}
     */
    public function handle(): array
    {
        $counts = [
            'expired' => $this->expire(),
            'deleted' => 0,
            'ip_cleared' => 0,
            'purged' => 0,
        ];

        $counts['deleted'] += $this->delete(fn (Builder $q) => $q->whereNull('handoff_at')->whereNull('lead_id')->whereNull('banned_at')
            ->whereRaw('coalesce(last_message_at, created_at) < ?', [now()->subDays(self::BOT_ONLY_DAYS)]));
        $counts['deleted'] += $this->delete(fn (Builder $q) => $q->whereNotNull('handoff_at')->whereNull('lead_id')->whereNull('banned_at')
            ->whereRaw('coalesce(last_message_at, created_at) < ?', [now()->subDays(self::HANDED_OFF_DAYS)]));
        $counts['deleted'] += $this->delete(fn (Builder $q) => $q->whereNotNull('lead_id')->whereNull('banned_at')
            ->whereRaw('coalesce(last_message_at, created_at) < ?', [now()->subMonths(self::LEAD_MONTHS)]));
        $counts['deleted'] += $this->delete(fn (Builder $q) => $q->where('banned_at', '<', now()->subMonths(self::BAN_DELETE_MONTHS)));

        $counts['ip_cleared'] = WebChat::query()->whereNotNull('ip_hash')->whereRaw('coalesce(last_message_at, created_at) < ?', [now()->subDays(self::IP_HASH_DAYS)])->update(['ip_hash' => null]);
        $counts['purged'] = $this->purgeBanned();

        Log::info('Website chats pruned', $counts);

        return $counts;
    }

    private function expire(): int
    {
        $count = 0;
        $cutoff = now()->subHours(max(1, (int) config('chatbot.web.expire_hours', 24)));

        WebChat::query()
            ->whereNull('closed_at')
            ->whereNull('banned_at')
            ->whereRaw('coalesce(last_message_at, created_at) < ?', [$cutoff])
            ->chunkById(500, function ($chats) use (&$count): void {
                foreach ($chats as $chat) {
                    $chat->forceFill([
                        'closed_at' => now(),
                        'conversation_status' => ConversationStatus::Closed,
                        'mode' => ChatMode::Bot,
                        'dialog' => null,
                    ]);
                    $chat->resetAiContext();
                    $chat->save();
                    WebChatVersion::bump($chat->id);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  callable(Builder<WebChat>): mixed  $scope
     */
    private function delete(callable $scope): int
    {
        $count = 0;

        $query = WebChat::query();
        $scope($query);

        $query->chunkById(500, function ($chats) use (&$count): void {
            $count += WebChat::query()->whereKey($chats->modelKeys())->delete();
        });

        return $count;
    }

    private function purgeBanned(): int
    {
        $count = 0;

        WebChat::query()
            ->whereNotNull('banned_at')
            ->whereNull('purged_at')
            ->where('banned_at', '<', now()->subDays(self::BAN_PURGE_DAYS))
            ->chunkById(500, function ($chats) use (&$count): void {
                foreach ($chats as $chat) {
                    $chat->messages()->delete();
                    $chat->forceFill(['name' => null, 'phone' => null, 'first_page' => null, 'dialog' => null, 'unread_count' => 0, 'purged_at' => now()])->save();
                    $count++;
                }
            });

        return $count;
    }
}
