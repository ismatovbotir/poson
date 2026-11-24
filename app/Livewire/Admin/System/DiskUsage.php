<?php

namespace App\Livewire\Admin\System;

use App\Actions\Monitoring\MonitoringActionException;
use App\Actions\Monitoring\StartDiskMeasurement;
use App\Actions\Monitoring\UpdateDiskThresholds;
use App\Enums\DiskTarget;
use App\Livewire\Forms\Admin\System\DiskThresholdsForm;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Monitoring\DiskHistory;
use App\Support\Monitoring\DiskRun;
use App\Support\Monitoring\DiskThresholds;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin → Monitoring → "Project size" (plan 37): size of the project folder
 * (total, top-level buckets, storage detail), measured only when an Admin
 * clicks "Measure now" (a queued job writes its progress to the row
 * disk:progress). Lazy child of the Monitoring page, so the parent's query
 * budget and poll stay as they are.
 *
 * monitoring.view is checked in boot() (every request, so the 2 s poll
 * re-authorizes, DM-9, DM-26) and mount(); measure and the limits form need
 * monitoring.manage. The poll and every render read the database only
 * (one progress row, the snapshot rows, the cached limits): never the
 * filesystem. No path, name or hostname ever reaches the HTML (DM-8).
 */
#[Lazy]
class DiskUsage extends Component
{
    /**
     * The client stops polling after this many seconds (DM-26).
     */
    public const POLL_LIMIT = 1200;

    /**
     * 30 or 90 days of history; anything else means 30 (DM-10).
     */
    #[Url]
    public mixed $range = 30;

    public DiskThresholdsForm $form;

    #[Locked]
    public ?int $pollStartedAt = null;

    public function boot(): void
    {
        $this->authorize('monitoring.view');
    }

    public function mount(): void
    {
        $this->authorize('monitoring.view');

        $this->range = $this->days();
        $this->form->fillFrom(app(DiskThresholds::class));
        $this->pollStartedAt = $this->run()->isActive() ? now()->getTimestamp() : null;
    }

    private ?DiskRun $run = null;

    /**
     * The progress row, read once per request.
     */
    protected function run(): DiskRun
    {
        return $this->run ??= DiskRun::current();
    }

    /**
     * The target of wire:poll while a run is active: re-authorized by boot(),
     * starts the 20-minute client limit on the first tick, reads one row.
     */
    public function poll(): void
    {
        $this->authorize('monitoring.view');

        $this->pollStartedAt = $this->run()->isActive() ? ($this->pollStartedAt ?? now()->getTimestamp()) : null;
    }

    public function placeholder(): View
    {
        return view('livewire.admin.system.disk-usage-placeholder');
    }

    /**
     * Re-read only: never measures.
     */
    public function refresh(): void
    {
        $this->authorize('monitoring.view');
    }

    public function checkAgain(): void
    {
        $this->authorize('monitoring.view');

        $this->pollStartedAt = now()->getTimestamp();
    }

    public function setRange(mixed $range): void
    {
        $this->authorize('monitoring.view');

        $this->range = is_scalar($range) ? $range : 30;
        $this->range = $this->days();
    }

    public function measure(StartDiskMeasurement $action): void
    {
        $this->authorize('monitoring.manage');

        // A loose limit on every attempt, whether or not it starts a run.
        $limiter = 'monitoring:disk-click:'.auth()->id();

        if (RateLimiter::tooManyAttempts($limiter, 30)) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('monitoring.errors.rate'), type: 'error');

            return;
        }

        RateLimiter::hit($limiter, 60);

        try {
            $started = $action->handle(auth()->user());
        } catch (MonitoringActionException $exception) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('monitoring.errors.'.$exception->reason), type: 'error');

            return;
        }

        $this->pollStartedAt = $started ? now()->getTimestamp() : $this->pollStartedAt;

        $this->dispatch('toast', title: $started ? __('monitoring.disk.toasts.started') : __('monitoring.disk.toasts.not_started'));
    }

    public function saveLimits(UpdateDiskThresholds $action): void
    {
        $this->authorize('monitoring.manage');

        $this->form->validate();

        try {
            $action->handle(auth()->user(), (int) $this->form->warn, (int) $this->form->critical, request()->ip());
        } catch (MonitoringActionException $exception) {
            if ($exception->reason === 'password') {
                $this->redirect(PasswordConfirmation::url(route('admin.monitoring')), navigate: false);

                return;
            }

            $this->dispatch('toast', title: __('admin.toast.error'), body: __('monitoring.errors.'.$exception->reason), type: 'error');

            return;
        }

        $this->form->fillFrom(app(DiskThresholds::class));
        $this->dispatch('toast', title: __('monitoring.disk.toasts.saved'));
    }

    /**
     * Normalised range: 30 or 90.
     */
    protected function days(): int
    {
        return is_scalar($this->range) && is_numeric($this->range) && in_array((int) $this->range, [30, 90], true) && (string) (int) $this->range === (string) $this->range ? (int) $this->range : 30;
    }

    public function render(): View
    {
        $days = $this->days();
        $run = $this->run();
        $pollExpired = $run->isActive() && $this->pollStartedAt !== null && now()->getTimestamp() - $this->pollStartedAt > self::POLL_LIMIT;

        // While a run is active the poll renders the progress block only: no history query.
        $history = [];

        if (! $run->isActive()) {
            $rows = DB::table('disk_snapshots')
                ->select(['target', 'taken_on', 'size_bytes', 'file_count', 'status', 'measured_at'])
                ->where('taken_on', '>=', now('Asia/Tashkent')->subDays($days + 2 * max(DiskHistory::CHANGE_DAYS))->toDateString())
                ->orderBy('taken_on')
                ->get();

            $history = DiskHistory::build($rows, $days);
        }

        $total = $history[DiskTarget::ProjectTotal->value] ?? null;

        return view('livewire.admin.system.disk-usage', [
            'run' => $run,
            'polling' => $run->isActive() && ! $pollExpired,
            'pollExpired' => $pollExpired,
            'days' => $days,
            'history' => $history,
            'activeRun' => $run->isActive(),
            'total' => $total,
            'limits' => app(DiskThresholds::class)->gb(),
            'canManage' => auth()->user()->can('monitoring.manage'),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
        ]);
    }
}
