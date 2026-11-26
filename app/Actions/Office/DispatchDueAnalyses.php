<?php

namespace App\Actions\Office;

use App\Enums\AnalysisStatus;
use App\Enums\BusinessChatKind;
use App\Enums\GroupStatus;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysis;
use App\Support\Office\OfficeSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The office:analyse-due run (every minute, plan 41 §6.1, VO-16):
 *
 *  1. queued / running rows older than the job timeout + the queue's
 *     retry_after + 60 s are failed "stale" (a lost worker never blocks a chat);
 *  2. nothing more while the office AI is off;
 *  3. at most office.max_pending (3) analyses queued or running at once,
 *     at most office.dispatch_batch (10) per run: client chats whose
 *     analysis_due_at has passed and that have no pending analysis, oldest
 *     due first, each queued by QueueBusinessChatAnalysis under its row lock;
 *  4. plan 44 (OG-4, OG-11): the slots left go to tracked, assigned groups
 *     whose analysis_due_at has passed (only while groups are on), each
 *     queued by QueueGroupAnalysis under its row lock. Group analyses count
 *     toward the same max_pending (VO-16 concurrency) and stale rule; while
 *     groups are due one slot is kept for them (no starvation).
 */
class DispatchDueAnalyses
{
    public function __construct(protected QueueBusinessChatAnalysis $queue, protected QueueGroupAnalysis $queueGroup) {}

    /**
     * @return array{stale: int, queued: int}
     */
    public function handle(): array
    {
        $staleSeconds = (int) config('office.job_timeout', 120) + (int) config('queue.connections.'.config('queue.default').'.retry_after', 300) + 60;

        $stale = BusinessChatAnalysis::query()
            ->whereIn('status', AnalysisStatus::pendingValues())
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['status' => AnalysisStatus::Failed->value, 'error' => 'stale', 'finished_at' => now()]);

        $stale += TelegramGroupAnalysis::query()
            ->whereIn('status', AnalysisStatus::pendingValues())
            ->where('created_at', '<', now()->subSeconds($staleSeconds - (int) config('office.job_timeout', 120) + (int) config('office.group_job_timeout', 240)))
            ->update(['status' => AnalysisStatus::Failed->value, 'error' => 'stale', 'finished_at' => now()]);

        if (! OfficeSettings::aiEnabled()) {
            return ['stale' => $stale, 'queued' => 0];
        }

        $slots = (int) config('office.max_pending', 3) - BusinessChatAnalysis::query()->whereIn('status', AnalysisStatus::pendingValues())->count()
            - TelegramGroupAnalysis::query()->whereIn('status', AnalysisStatus::pendingValues())->count();
        $limit = min((int) config('office.dispatch_batch', 10), $slots);

        if ($limit <= 0) {
            return ['stale' => $stale, 'queued' => 0];
        }

        // Code review 2: with groups due, one slot is kept for them (with a single slot, every other minute),
        // so a steady chat backlog never starves the groups. No group due → exactly the plan 41 behaviour.
        $reserve = 0;

        if (OfficeSettings::groupsEnabled() && $this->dueGroups()->exists()) {
            $reserve = $limit > 1 || now()->minute % 2 === 0 ? 1 : 0;
        }

        $ids = $limit - $reserve <= 0 ? collect() : BusinessChat::query()
            ->where('kind', BusinessChatKind::Client->value)
            ->whereNotNull('analysis_due_at')
            ->where('analysis_due_at', '<=', now())
            ->whereDoesntHave('analyses', fn (Builder $query) => $query->whereIn('status', AnalysisStatus::pendingValues()))
            ->orderBy('analysis_due_at')
            ->limit($limit - $reserve)
            ->pluck('id');

        $queued = 0;

        foreach ($ids as $id) {
            $queued += DB::transaction(function () use ($id): int {
                $chat = BusinessChat::query()->whereKey($id)->lockForUpdate()->first();

                if (! $chat instanceof BusinessChat || ! $chat->isClient() || $chat->analysis_due_at === null || $chat->analysis_due_at->isFuture() || $chat->hasPendingAnalysis()) {
                    return 0;
                }

                return $this->queue->handle($chat, BusinessChatAnalysis::TRIGGER_DEBOUNCE) === QueueBusinessChatAnalysis::QUEUED ? 1 : 0;
            });
        }

        return ['stale' => $stale, 'queued' => $queued + $this->groups($limit - $queued)];
    }

    /**
     * Due tracked groups into the slots the chats left (plan 44): nothing while groups are off.
     */
    protected function groups(int $limit): int
    {
        if ($limit <= 0 || ! OfficeSettings::groupsEnabled()) {
            return 0;
        }

        $ids = $this->dueGroups()->orderBy('analysis_due_at')->limit($limit)->pluck('id');

        $queued = 0;

        foreach ($ids as $id) {
            $queued += DB::transaction(function () use ($id): int {
                $group = TelegramGroup::query()->whereKey($id)->lockForUpdate()->first();

                if (! $group instanceof TelegramGroup || $group->analysis_due_at === null || $group->analysis_due_at->isFuture() || QueueGroupAnalysis::hasPending($group)) {
                    return 0;
                }

                return $this->queueGroup->handle($group, TelegramGroupAnalysis::TRIGGER_DEBOUNCE) === QueueGroupAnalysis::QUEUED ? 1 : 0;
            });
        }

        return $queued;
    }

    /**
     * Tracked, assigned groups whose analysis is due and that have nothing pending.
     *
     * @return Builder<TelegramGroup>
     */
    protected function dueGroups(): Builder
    {
        return TelegramGroup::query()
            ->where('status', GroupStatus::Tracked->value)
            ->whereNotNull('assignee_id')
            ->whereNotNull('analysis_due_at')
            ->where('analysis_due_at', '<=', now())
            ->whereDoesntHave('analyses', fn (Builder $query) => $query->whereIn('status', AnalysisStatus::pendingValues()));
    }
}
