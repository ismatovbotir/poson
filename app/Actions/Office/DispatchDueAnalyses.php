<?php

namespace App\Actions\Office;

use App\Enums\AnalysisStatus;
use App\Enums\BusinessChatKind;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
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
 *     due first, each queued by QueueBusinessChatAnalysis under its row lock.
 */
class DispatchDueAnalyses
{
    public function __construct(protected QueueBusinessChatAnalysis $queue) {}

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

        if (! OfficeSettings::aiEnabled()) {
            return ['stale' => $stale, 'queued' => 0];
        }

        $slots = (int) config('office.max_pending', 3) - BusinessChatAnalysis::query()->whereIn('status', AnalysisStatus::pendingValues())->count();
        $limit = min((int) config('office.dispatch_batch', 10), $slots);

        if ($limit <= 0) {
            return ['stale' => $stale, 'queued' => 0];
        }

        $ids = BusinessChat::query()
            ->where('kind', BusinessChatKind::Client->value)
            ->whereNotNull('analysis_due_at')
            ->where('analysis_due_at', '<=', now())
            ->whereDoesntHave('analyses', fn (Builder $query) => $query->whereIn('status', AnalysisStatus::pendingValues()))
            ->orderBy('analysis_due_at')
            ->limit($limit)
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

        return ['stale' => $stale, 'queued' => $queued];
    }
}
