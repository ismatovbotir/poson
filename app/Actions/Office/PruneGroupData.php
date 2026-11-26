<?php

namespace App\Actions\Office;

use App\Enums\AnalysisStatus;
use App\Enums\GroupStatus;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Support\Office\OfficeLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Telegram groups retention (plan 44 §2, OG-12), run by PruneBusinessData
 * (office:prune, nightly), chunked, counts only:
 *
 *  - a group silent for 12 months (last_message_at, else updated_at) that
 *    still holds messages, analyses or threads → all three deleted; the
 *    group row stays (members stay, they are the staff's role record, but their
 *    shared phones are nulled);
 *  - members who left or were removed and were last seen more than 12
 *    months ago → deleted (their messages cascade);
 *  - analysis drafts (by created_at) and thread drafts (by updated_at: a re-analysis rewrites the draft and
 *    touches the thread) older than 30 days → nulled;
 *  - any group that is not tracked but still holds messages, members, threads or analyses → purged;
 *  - untracked group stubs silent for 30 days → deleted (a group whose bot
 *    left stays untracked or tracked: only untracked stubs go);
 *  - ignored stubs → title and username nulled.
 * Leads are never deleted. A group with a queued or running analysis is
 * skipped, and every group is changed under its OfficeLock; a held lock
 * skips it until the next night.
 */
class PruneGroupData
{
    public const CHUNK = 500;

    /**
     * @return array<string, int>
     */
    public function handle(): array
    {
        $retention = (array) config('office.retention');
        $counts = ['group_messages' => 0, 'group_analyses' => 0, 'group_threads' => 0, 'group_members' => 0, 'group_drafts' => 0, 'group_stubs' => 0, 'group_ignored' => 0, 'group_skipped' => 0];

        // Silent groups that still hold content.
        $silent = now()->subMonths((int) ($retention['group_months'] ?? 12));
        $this->eachGroup(
            TelegramGroup::query()
                ->where(fn (Builder $query) => $query->where('last_message_at', '<', $silent)->orWhere(fn (Builder $inner) => $inner->whereNull('last_message_at')->where('updated_at', '<', $silent)))
                ->where(fn (Builder $query) => $query->whereHas('messages')->orWhereHas('analyses')->orWhereHas('threads')),
            function (TelegramGroup $group) use (&$counts): void {
                TelegramGroupMember::query()->where('telegram_group_id', $group->id)->whereNotNull('phone')->update(['phone' => null]);
                $counts['group_messages'] += PurgeGroupData::deleteChunked(TelegramGroupMessage::class, $group->id);
                $counts['group_threads'] += PurgeGroupData::deleteChunked(TelegramGroupThread::class, $group->id);
                $counts['group_analyses'] += PurgeGroupData::deleteChunked(TelegramGroupAnalysis::class, $group->id);
            },
            $counts,
        );

        // Members who left and have not been seen for 12 months.
        $gone = now()->subMonths((int) ($retention['group_member_months'] ?? 12));
        $stale = fn (Builder $query) => $query->whereIn('member_status', ['left', 'kicked'])->where('last_seen_at', '<', $gone);
        $this->eachGroup(
            TelegramGroup::query()->whereHas('members', $stale),
            function (TelegramGroup $group) use ($stale, &$counts): void {
                do {
                    $ids = TelegramGroupMember::query()->where('telegram_group_id', $group->id)->where($stale)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
                    $counts['group_members'] += $ids === [] ? 0 : TelegramGroupMember::query()->whereKey($ids)->delete();
                } while (count($ids) === self::CHUNK);
            },
            $counts,
        );

        // Belt and braces (T7): a group that is not tracked never keeps data (a purge or an ingest race left some).
        $this->eachGroup(
            TelegramGroup::query()->where('status', '!=', GroupStatus::Tracked->value)
                ->where(fn (Builder $query) => $query->whereHas('messages')->orWhereHas('analyses')->orWhereHas('threads')->orWhereHas('members')),
            function (TelegramGroup $group) use (&$counts): void {
                $purged = app(PurgeGroupData::class)->handle($group);
                $counts['group_messages'] += $purged['messages'];
                $counts['group_threads'] += $purged['threads'];
                $counts['group_analyses'] += $purged['analyses'];
                $counts['group_members'] += $purged['members'];
            },
            $counts,
        );

        // Old drafts.
        $draftBefore = now()->subDays((int) ($retention['group_draft_days'] ?? 30));
        $counts['group_drafts'] += $this->nullChunked(TelegramGroupAnalysis::query()->whereNotNull('draft_reply')->where('created_at', '<', $draftBefore), ['draft_reply' => null]);
        $counts['group_drafts'] += $this->nullChunked(TelegramGroupThread::query()->whereNotNull('draft')->where('updated_at', '<', $draftBefore), ['draft' => null]);

        // Untracked stubs silent for 30 days.
        $stubBefore = now()->subDays((int) ($retention['group_stub_days'] ?? 30));
        $this->eachGroup(
            TelegramGroup::query()->where('status', GroupStatus::Untracked->value)
                ->where(fn (Builder $query) => $query->where('last_message_at', '<', $stubBefore)->orWhere(fn (Builder $inner) => $inner->whereNull('last_message_at')->where('updated_at', '<', $stubBefore))),
            function (TelegramGroup $group) use (&$counts): void {
                $group->delete();
                $counts['group_stubs']++;
            },
            $counts,
        );

        // Ignored stubs never keep a title.
        $counts['group_ignored'] = $this->nullChunked(
            TelegramGroup::query()->where('status', GroupStatus::Ignored->value)->where(fn (Builder $query) => $query->whereNotNull('title')->orWhereNotNull('username')),
            ['title' => null, 'username' => null],
        );

        return $counts;
    }

    /**
     * Whether the group still holds any message, member, analysis or thread.
     */
    public static function holdsData(TelegramGroup $group): bool
    {
        return $group->messages()->exists() || $group->members()->exists() || $group->analyses()->exists() || $group->threads()->exists();
    }

    /**
     * Run $change for each matching group (chunked) under its analysis lock; groups with a pending
     * analysis or a held lock are skipped (counted).
     *
     * @param  Builder<TelegramGroup>  $query
     * @param  callable(TelegramGroup): void  $change
     * @param  array<string, int>  $counts
     */
    private function eachGroup(Builder $query, callable $change, array &$counts): void
    {
        $query->whereDoesntHave('analyses', fn (Builder $analyses) => $analyses->whereIn('status', AnalysisStatus::pendingValues()))
            ->select(['id'])
            ->chunkById(self::CHUNK, function (Collection $groups) use ($change, &$counts): void {
                foreach ($groups as $group) {
                    $lock = OfficeLock::forGroup($group->id);

                    if (! $lock->get()) {
                        $counts['group_skipped']++;

                        continue;
                    }

                    try {
                        $change($group);
                    } finally {
                        $lock->release();
                    }
                }
            });
    }

    /**
     * Update the matching rows CHUNK at a time (by key), returning how many changed.
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $values
     */
    private function nullChunked(Builder $query, array $values): int
    {
        $total = 0;

        do {
            $ids = (clone $query)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();
            $total += $ids === [] ? 0 : $query->getModel()->newQuery()->whereKey($ids)->update($values);
        } while (count($ids) === self::CHUNK);

        return $total;
    }
}
