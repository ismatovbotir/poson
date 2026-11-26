<?php

namespace App\Jobs;

use App\Actions\Office\RunGroupAnalysis;
use App\Enums\AnalysisStatus;
use App\Models\TelegramGroupAnalysis;
use App\Services\Ai\ChatModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One Virtual office analysis of a tracked Telegram group (plan 44 §2), the
 * twin of AnalyseBusinessChat: payload the analysis id only (no text);
 * dispatched ->afterCommit() on the ai-admin queue; tries 1; timeout
 * office.group_job_timeout (240 s: 1 router + up to 3 agent calls, below
 * the queue's retry_after); the stale rule of DispatchDueAnalyses frees a
 * lost run. failed() marks a still pending row failed.
 */
class AnalyseTelegramGroup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $analysisId)
    {
        $this->timeout = (int) config('office.group_job_timeout', 240);
        $this->onQueue((string) config('office.queue', 'ai-admin'));
    }

    public function handle(ChatModel $model, RunGroupAnalysis $run): void
    {
        $run->handle($this->analysisId, $model);
    }

    public function failed(?Throwable $error): void
    {
        TelegramGroupAnalysis::query()->whereKey($this->analysisId)->whereIn('status', AnalysisStatus::pendingValues())
            ->update(['status' => AnalysisStatus::Failed->value, 'error' => 'error', 'finished_at' => now()]);
    }
}
