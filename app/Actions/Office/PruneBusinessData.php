<?php

namespace App\Actions\Office;

use App\Enums\AnalysisStatus;
use App\Enums\BusinessChatKind;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\BusinessConnection;
use App\Support\Office\OfficeLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Virtual office retention (plan 41 §9, VO-18), office:prune nightly,
 * chunked, counts only:
 *
 *  - unmarked stubs 14 days after the last inbound message → deleted;
 *  - ignored chats → names nulled 14 days after the last inbound (the row
 *    stays, so the chat is never surfaced again);
 *  - client chats silent for 12 months (last_message_at, else updated_at)
 *    that still hold messages or analyses → both deleted; the chat row too
 *    unless it is linked to a lead (a purged lead chat holds nothing, so it
 *    is never selected again);
 *  - analyses: the newest 5 per chat are kept (chunked per chats);
 *    drafts older than 30 days nulled;
 *  - connections that are not allowed and inactive for 30 days → deleted.
 * Telegram groups (plan 44, OG-12) are pruned by PruneGroupData, called last.
 * A chat with a queued or running analysis is skipped, and every chat is
 * changed under its analysis lock (OfficeLock); a held lock skips it until
 * the next night. (Telegram-deleted messages are nulled at once by
 * HandleBusinessUpdate.)
 */
class PruneBusinessData
{
    public const CHUNK = 500;

    /**
     * @return array<string, int>
     */
    public function handle(): array
    {
        $retention = (array) config('office.retention');
        $counts = ['unmarked' => 0, 'ignored' => 0, 'client_messages' => 0, 'client_chats' => 0, 'analyses' => 0, 'drafts' => 0, 'feedback' => 0, 'connections' => 0, 'skipped' => 0];

        // Unmarked stubs.
        $before = now()->subDays((int) $retention['unmarked_days']);
        $this->eachChat(
            BusinessChat::query()->where('kind', BusinessChatKind::Unmarked->value)
                ->where(fn (Builder $query) => $query->where('last_inbound_at', '<', $before)->orWhere(fn (Builder $inner) => $inner->whereNull('last_inbound_at')->where('created_at', '<', $before))),
            function (BusinessChat $chat) use (&$counts): void {
                $chat->delete();
                $counts['unmarked']++;
            },
            $counts,
        );

        // Ignored: names nulled.
        $counts['ignored'] = BusinessChat::query()->where('kind', BusinessChatKind::Ignored->value)
            ->where(fn (Builder $query) => $query->whereNotNull('first_name')->orWhereNotNull('username'))
            ->where(fn (Builder $query) => $query->where('last_inbound_at', '<', now()->subDays((int) $retention['ignored_names_days']))->orWhereNull('last_inbound_at'))
            ->update(['first_name' => null, 'username' => null]);

        // Silent client chats that still hold content.
        $silent = now()->subMonths((int) $retention['client_months']);
        $this->eachChat(
            BusinessChat::query()->where('kind', BusinessChatKind::Client->value)
                ->where(fn (Builder $query) => $query->where('last_message_at', '<', $silent)->orWhere(fn (Builder $inner) => $inner->whereNull('last_message_at')->where('updated_at', '<', $silent)))
                ->where(fn (Builder $query) => $query->whereHas('messages')->orWhereHas('analyses')->orWhereNull('lead_id')),
            function (BusinessChat $chat) use (&$counts): void {
                $counts['client_messages'] += $chat->messages()->delete();
                $counts['analyses'] += $chat->analyses()->delete();

                if ($chat->lead_id === null) {
                    $chat->delete();
                    $counts['client_chats']++;
                }
            },
            $counts,
        );

        // Keep the newest analyses per chat, chunked over the chats.
        $keep = max(1, (int) $retention['analyses_kept']);
        BusinessChat::query()->whereHas('analyses')->select('id')->chunkById(self::CHUNK, function (Collection $chats) use ($keep, &$counts): void {
            $over = BusinessChatAnalysis::query()->whereIn('business_chat_id', $chats->pluck('id')->all())
                ->select('business_chat_id')->groupBy('business_chat_id')->havingRaw('count(*) > ?', [$keep])->pluck('business_chat_id');

            foreach ($over as $chatId) {
                $kept = BusinessChatAnalysis::query()->where('business_chat_id', $chatId)->orderByDesc('id')->limit($keep)->pluck('id')->all();
                $counts['analyses'] += BusinessChatAnalysis::query()->where('business_chat_id', $chatId)->whereNotIn('id', $kept)
                    ->whereNotIn('status', AnalysisStatus::pendingValues())->delete();
            }
        });

        // Old drafts.
        $counts['drafts'] = BusinessChatAnalysis::query()->whereNotNull('draft_reply')->where('created_at', '<', now()->subDays((int) $retention['draft_days']))->update(['draft_reply' => null]);

        // Plan 46: feedback texts 30 days after the last update (promoted or not), and rows whose analysis is gone.
        $counts['feedback'] = ForgetDraftFeedback::prune();

        // Strangers' connections (they never have chats: their messages are dropped).
        $counts['connections'] = BusinessConnection::query()->where('is_allowed', false)
            ->where('updated_at', '<', now()->subDays((int) $retention['disallowed_connection_days']))
            ->whereDoesntHave('chats')->delete();

        // Plan 44: the Telegram groups rules (own counts, group_*).
        return $counts + app(PruneGroupData::class)->handle();
    }

    /**
     * Run $change for each matching chat (chunked) under its analysis lock; chats with a pending analysis
     * or a held lock are skipped (counted).
     *
     * @param  Builder<BusinessChat>  $query
     * @param  callable(BusinessChat): void  $change
     * @param  array<string, int>  $counts
     */
    private function eachChat(Builder $query, callable $change, array &$counts): void
    {
        $query->whereDoesntHave('analyses', fn (Builder $analyses) => $analyses->whereIn('status', AnalysisStatus::pendingValues()))
            ->select(['id', 'lead_id'])
            ->chunkById(self::CHUNK, function (Collection $chats) use ($change, &$counts): void {
                foreach ($chats as $chat) {
                    $lock = OfficeLock::forChat($chat->id);

                    if (! $lock->get()) {
                        $counts['skipped']++;

                        continue;
                    }

                    try {
                        $change($chat);
                    } finally {
                        $lock->release();
                    }
                }
            });
    }
}
