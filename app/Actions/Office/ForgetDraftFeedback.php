<?php

namespace App\Actions\Office;

use App\Models\OfficeDraftFeedback;
use Illuminate\Database\Eloquent\Builder;

/**
 * Nulls the staff texts of draft feedback (plan 46 §2.2, ED-3, ED46-8): note, the draft snapshot and the
 * final text. The row stays as a count (outcome, reason, role, version); deleting a chat or group deletes
 * its rows (cascade). Called at every site that already nulls or deletes drafts: unmarking a chat, wiping
 * the office data, a Telegram message delete, Forget a group member (every thread of the group), untracking
 * or wiping a group (PurgeGroupData), and the retention rules (30 days after the last update, promoted or
 * not, and rows whose analysis link is gone). Idempotent, writes only rows that still hold a text.
 */
final class ForgetDraftFeedback
{
    private const BLANK = ['note' => null, 'draft_text' => null, 'final_text' => null];

    /**
     * All feedback of some business chats (unmark, wipe).
     *
     * @param  list<int>|int  $chatIds
     */
    public static function forChats(array|int $chatIds): int
    {
        return self::blank(OfficeDraftFeedback::query()->whereIn('business_chat_id', (array) $chatIds));
    }

    /**
     * The feedback on some business chat analyses (a Telegram message delete).
     *
     * @param  list<int>  $analysisIds
     */
    public static function forChatAnalyses(array $analysisIds): int
    {
        return $analysisIds === [] ? 0 : self::blank(OfficeDraftFeedback::query()->whereIn('business_chat_analysis_id', $analysisIds));
    }

    /**
     * Every feedback row of a group, whatever thread or member it belongs to (Forget, untrack, group wipe, ED46-8).
     */
    public static function forGroup(int $groupId): int
    {
        return self::blank(OfficeDraftFeedback::query()->where('telegram_group_id', $groupId));
    }

    /**
     * Retention: texts older than the retention days since the last update (promoted rows too), then orphans.
     */
    public static function prune(): int
    {
        $before = now()->subDays((int) config('office.retention.feedback_days', 30));

        return self::blankChunked(OfficeDraftFeedback::query()->where('updated_at', '<', $before))
            + self::blankChunked(OfficeDraftFeedback::query()->where(fn (Builder $query) => $query
                ->where(fn (Builder $chat) => $chat->whereNotNull('business_chat_id')->whereNull('business_chat_analysis_id'))
                ->orWhere(fn (Builder $group) => $group->whereNotNull('telegram_group_id')->where(fn (Builder $link) => $link->whereNull('telegram_group_thread_id')->orWhereNull('telegram_group_analysis_id')))));
    }

    /**
     * The nightly sweep in chunks of ids (a big backlog never becomes one huge UPDATE).
     *
     * @param  Builder<OfficeDraftFeedback>  $query
     */
    private static function blankChunked(Builder $query): int
    {
        $total = 0;

        $query->where(fn (Builder $has) => $has->whereNotNull('note')->orWhereNotNull('draft_text')->orWhereNotNull('final_text'))
            ->select('id')
            ->chunkById(500, function ($rows) use (&$total): void {
                $total += self::blank(OfficeDraftFeedback::query()->whereKey($rows->modelKeys()));
            });

        return $total;
    }

    /**
     * @param  Builder<OfficeDraftFeedback>  $query
     */
    private static function blank(Builder $query): int
    {
        return $query->where(fn (Builder $has) => $has->whereNotNull('note')->orWhereNotNull('draft_text')->orWhereNotNull('final_text'))
            ->toBase()->update(self::BLANK);
    }
}
