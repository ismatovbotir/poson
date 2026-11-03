<?php

namespace App\Livewire\Admin\System;

use App\Actions\Monitoring\ForgetFailedJobs;
use App\Actions\Monitoring\MonitoringActionException;
use App\Actions\Monitoring\RetryFailedJobs;
use App\Actions\Monitoring\RunTaskNow;
use App\Actions\Telegram\CheckTelegramWebhook;
use App\Jobs\RunScheduledTask;
use App\Models\MonitoringState;
use App\Models\ScheduledTaskRun;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Monitoring\FailedJobs;
use App\Support\Monitoring\Heartbeat;
use App\Support\Monitoring\MonitoredTasks;
use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Monitoring\PingUrl;
use App\Support\Monitoring\QueueStats;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * System → Monitoring (/admin/monitoring, plan 17 §5): scheduler and worker
 * heartbeats, queue figures, the scheduled tasks with their latest runs, and
 * the failed jobs with retry / forget.
 *
 * monitoring.view is checked in mount() and again in boot(), which runs on
 * every request, so the 30 s poll re-checks it (MON-S8). Writes go through
 * the three Actions, which re-check monitoring.manage and the recent password
 * confirmation. Failed-job rows hold only uuid, queue, time, class and a
 * redacted one-line error: never payload data.
 */
#[Layout('layouts::admin')]
class Monitoring extends Component
{
    use WithPagination;

    /**
     * A snapshot older than this many seconds is reported as stale.
     */
    public const SNAPSHOT_STALE_AFTER = 180;

    public const RUNS_SHOWN = 20;

    /**
     * Task whose last runs are expanded.
     */
    #[Locked]
    public ?string $expanded = null;

    /**
     * Failed job whose details are open.
     */
    #[Locked]
    public ?string $detailUuid = null;

    /**
     * Ticked failed-job uuids (validated again by the actions).
     *
     * @var list<string>
     */
    public array $selected = [];

    public function boot(): void
    {
        $this->authorize('monitoring.view');
    }

    public function mount(): void
    {
        $this->authorize('monitoring.view');
    }

    public function toggleRuns(string $key): void
    {
        $this->authorize('monitoring.view');

        $this->expanded = $this->expanded === $key ? null : mb_substr($key, 0, 191);
    }

    public function showFailed(string $uuid): void
    {
        $this->authorize('monitoring.view');

        $this->detailUuid = Str::isUuid($uuid) && FailedJobs::find($uuid) !== null ? strtolower($uuid) : null;
    }

    public function closeFailed(): void
    {
        $this->detailUuid = null;
    }

    public function confirmPassword(): void
    {
        $this->redirect(PasswordConfirmation::url(route('admin.monitoring')), navigate: false);
    }

    public function runNow(string $key, RunTaskNow $action): void
    {
        $this->authorize('monitoring.manage');

        $this->attempt(function () use ($action, $key): void {
            $action->handle(auth()->user(), $key);
            $this->dispatch('toast', title: __('monitoring.toasts.run_queued'), body: __('monitoring.toasts.run_queued_body'));
        });
    }

    public function retrySelected(RetryFailedJobs $action): void
    {
        $this->retry($this->selected, $action);
    }

    public function retryOne(string $uuid, RetryFailedJobs $action): void
    {
        $this->retry([$uuid], $action);
    }

    public function forgetSelected(ForgetFailedJobs $action): void
    {
        $this->forget($this->selected, $action);
    }

    public function forgetOne(string $uuid, ForgetFailedJobs $action): void
    {
        $this->forget([$uuid], $action);
    }

    /**
     * @param  array<mixed>  $ids
     */
    protected function retry(array $ids, RetryFailedJobs $action): void
    {
        $this->authorize('monitoring.manage');

        $this->attempt(function () use ($action, $ids): void {
            $count = $action->handle(auth()->user(), $ids);
            $this->afterBulk();
            $this->dispatch('toast', title: __('monitoring.toasts.retried', ['count' => $count]));
        });
    }

    /**
     * @param  array<mixed>  $ids
     */
    protected function forget(array $ids, ForgetFailedJobs $action): void
    {
        $this->authorize('monitoring.manage');

        $this->attempt(function () use ($action, $ids): void {
            $count = $action->handle(auth()->user(), $ids);
            $this->afterBulk();
            $this->dispatch('toast', title: __('monitoring.toasts.forgotten', ['count' => $count]));
        });
    }

    protected function afterBulk(): void
    {
        $this->selected = [];
        $this->detailUuid = null;
        $this->resetPage('failed');
    }

    /**
     * Run a write; a refused action becomes a toast, a missing password
     * confirmation a redirect to the confirm page.
     */
    protected function attempt(callable $write): void
    {
        try {
            $write();
        } catch (MonitoringActionException $exception) {
            if ($exception->reason === 'password') {
                $this->confirmPassword();

                return;
            }

            $this->dispatch('toast', title: __('admin.toast.error'), body: __('monitoring.errors.'.$exception->reason), type: 'error');
        }
    }

    public function render(): View
    {
        $states = MonitoringState::query()
            ->whereIn('key', [Heartbeat::SCHEDULER, Heartbeat::WORKER, MonitoredTasks::SNAPSHOT_KEY])
            ->get()->keyBy('key');

        $snapshot = $states->get(MonitoredTasks::SNAPSHOT_KEY);
        $tasks = $snapshot?->meta['tasks'] ?? [];

        $latest = $tasks === [] ? collect() : ScheduledTaskRun::query()
            ->whereIn('id', ScheduledTaskRun::query()->toBase()->selectRaw('max(id)')->groupBy('task'))
            ->get()->keyBy('task');

        $queue = QueueStats::snapshot();
        $failed = FailedJobs::page();
        $detail = $this->detailUuid === null ? null : FailedJobs::find($this->detailUuid);

        return view('livewire.admin.system.monitoring', [
            'scheduler' => $states->get(Heartbeat::SCHEDULER)?->ticked_at,
            'worker' => $states->get(Heartbeat::WORKER),
            'queue' => $queue,
            'snapshotAt' => $snapshot?->ticked_at,
            'snapshotStale' => $snapshot === null || $snapshot->ticked_at === null || $snapshot->ticked_at->lt(now()->subSeconds(self::SNAPSHOT_STALE_AFTER)),
            'tasks' => $tasks,
            'latest' => $latest,
            'runs' => $this->expanded !== null && isset($tasks[$this->expanded])
                ? ScheduledTaskRun::query()->where('task', $this->expanded)->orderByDesc('id')->limit(self::RUNS_SHOWN)->get()
                : collect(),
            'failed' => $failed,
            'detail' => $detail === null ? null : [
                'row' => $detail,
                'info' => FailedJobs::detail($detail->exception_excerpt),
            ],
            'retryAfter' => (int) config('queue.connections.database.retry_after'),
            'retryAfterTooLow' => config('queue.default') === 'database' && (int) config('queue.connections.database.retry_after') <= RunScheduledTask::TIMEOUT,
            'telegramConfigured' => MonitoringAlerts::isConfigured(),
            // Plan 14 v2 L4: open Telegram bot problems (a revoked token can't deliver its own alert).
            'telegramProblems' => CheckTelegramWebhook::openProblems(),
            'pingConfigured' => PingUrl::isConfigured(),
            'canManage' => auth()->user()->can('monitoring.manage'),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
        ]);
    }
}
