<?php

namespace App\Jobs;

use App\Actions\Office\RunBusinessChatAnalysis;
use App\Enums\AnalysisStatus;
use App\Models\BusinessChatAnalysis;
use App\Services\Ai\ChatModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One Virtual office analysis (plan 41 §6, VO-11): payload the analysis id
 * only (no text, VO-5); dispatched ->afterCommit() on the ai-admin queue;
 * tries 1; timeout office.job_timeout (120 s), above the two SDK call
 * timeouts (20 + 60 s) and below the queue's retry_after (300 s). The model
 * is resolved in handle(). failed() marks a still pending row failed.
 */
class AnalyseBusinessChat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $analysisId)
    {
        $this->timeout = (int) config('office.job_timeout', 120);
        $this->onQueue((string) config('office.queue', 'ai-admin'));
    }

    public function handle(ChatModel $model, RunBusinessChatAnalysis $run): void
    {
        $run->handle($this->analysisId, $model);
    }

    public function failed(?Throwable $error): void
    {
        BusinessChatAnalysis::query()->whereKey($this->analysisId)->whereIn('status', AnalysisStatus::pendingValues())
            ->update(['status' => AnalysisStatus::Failed->value, 'error' => 'error', 'finished_at' => now()]);
    }
}
