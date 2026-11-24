<?php

namespace App\Jobs;

use App\Actions\Monitoring\StartDiskMeasurement;
use App\Enums\DiskMeasureStatus;
use App\Enums\DiskRunError;
use App\Enums\DiskRunStatus;
use App\Enums\DiskTarget;
use App\Models\DiskSnapshot;
use App\Support\Monitoring\DiskMeasurement;
use App\Support\Monitoring\DiskRun;
use App\Support\Monitoring\FolderSizer;
use App\Support\Monitoring\ProblemMonitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Measure the project folder (plan 37). Started by an Admin click
 * (StartDiskMeasurement, which passes the run_id) or by a schedule: with no
 * run_id the job claims the run slot itself, so `Schedule::job(new
 * MeasureProjectSize)` works without changes.
 *
 * Queue "monitoring", one try, 300 s. A cache mutex (TTL timeout + 60)
 * keeps two jobs from walking at once. Progress goes to the row
 * disk:progress at most once a second (time throttled, with a heartbeat);
 * every write is conditional on the run_id, so a superseded run stops. The
 * final "done" and the snapshot rows are one transaction. Only after a done
 * run is the size alert evaluated (a partial result may raise a level, never
 * lower one). A failure stores an error code, never a message or a path.
 */
class MeasureProjectSize implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Must stay below the queue's retry_after (660 on the server), or a long run could start twice.
     */
    public const TIMEOUT = 240;

    public const MUTEX = 'monitoring:disk:run';

    public const JOB_RUN_PREFIX = 'monitoring:disk:job:';

    public int $tries = 1;

    public int $timeout = self::TIMEOUT;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    public function __construct(public ?string $runId = null)
    {
        $this->onQueue((string) config('monitoring.queue', 'monitoring'));
    }

    public function uniqueId(): string
    {
        return $this->runId ?? 'scheduled';
    }

    public function handle(StartDiskMeasurement $starter, FolderSizer $sizer): void
    {
        $lock = Cache::lock(self::MUTEX, self::TIMEOUT + 60);

        if (! $lock->get()) {
            // Another job holds the mutex: this run will never start, so end it (only applies while queued).
            DiskRun::fail($this->runId, DiskRunError::WorkerLost);

            return;
        }

        try {
            $runId = $this->runId ?? $starter->claim();

            if ($runId !== null) {
                $this->runId = $runId;

                // A scheduled job has no run_id in its payload: remember it so failed() can end the run.
                if ($this->job?->uuid() !== null) {
                    Cache::put(self::JOB_RUN_PREFIX.$this->job->uuid(), $runId, self::TIMEOUT + 600);
                }

                $this->measure($runId, $sizer);
            }
        } catch (Throwable $exception) {
            $this->failRun($exception);
        } finally {
            $lock->release();
        }
    }

    /**
     * The queue calls this on a timeout or when the job dies.
     */
    public function failed(?Throwable $exception = null): void
    {
        $this->failRun($exception);
    }

    private function measure(string $runId, FolderSizer $sizer): void
    {
        $startedAt = now();

        if (! DiskRun::write($runId, [DiskRunStatus::Queued], DiskRun::meta($runId, DiskRunStatus::Running, $startedAt))) {
            return;
        }

        $lastWrite = hrtime(true);
        $current = null;
        $carryFiles = $carryBytes = $lastFiles = $lastBytes = 0;

        $result = $sizer->measure(function (DiskTarget $bucket, int $files, int $bytes) use ($runId, $startedAt, &$lastWrite, &$current, &$carryFiles, &$carryBytes, &$lastFiles, &$lastBytes): bool {
            // The sizer counts per bucket; the page shows the whole run so far.
            if ($current !== null && $current !== $bucket) {
                $carryFiles += $lastFiles;
                $carryBytes += $lastBytes;
            }

            $current = $bucket;
            $lastFiles = $files;
            $lastBytes = $bytes;
            $now = hrtime(true);

            // At most one progress write a second (DM-27); it doubles as the heartbeat.
            if ($now - $lastWrite < 1_000_000_000) {
                return true;
            }

            $lastWrite = $now;

            return DiskRun::write($runId, [DiskRunStatus::Running], DiskRun::meta($runId, DiskRunStatus::Running, $startedAt, $bucket, $carryFiles + $files, $carryBytes + $bytes));
        });

        if ($result->aborted) {
            return;
        }

        if ($result->totalStatus() === DiskMeasureStatus::Unreadable) {
            DiskRun::fail($runId, DiskRunError::UnreadableRoot);

            return;
        }

        if (! $this->finish($runId, $startedAt, $result)) {
            return;
        }

        $total = $result->row(DiskTarget::ProjectTotal);

        try {
            (new ProblemMonitor)->evaluateProjectSize($total['bytes'], $result->totalStatus() !== DiskMeasureStatus::Ok);
        } catch (Throwable $exception) {
            Log::warning('Project size alert failed', ['exception' => $exception::class]);
        }
    }

    /**
     * Done + snapshot rows in one transaction, only while the run is still ours.
     */
    private function finish(string $runId, Carbon $startedAt, DiskMeasurement $result): bool
    {
        $total = $result->row(DiskTarget::ProjectTotal);
        $now = now();
        $day = $now->copy()->setTimezone('Asia/Tashkent')->toDateString();

        return DB::transaction(function () use ($runId, $startedAt, $result, $total, $now, $day): bool {
            $meta = DiskRun::meta($runId, DiskRunStatus::Done, $startedAt, null, $total['files'], $total['bytes'], $now, $result->budgetHit ? DiskRunError::Budget : null);

            if (! DiskRun::write($runId, [DiskRunStatus::Running], $meta)) {
                return false;
            }

            // An incomplete run never replaces a complete, larger figure of the same day.
            $kept = DiskSnapshot::query()->toBase()->where('taken_on', $day)->where('status', DiskMeasureStatus::Ok->value)->pluck('size_bytes', 'target');
            $rows = array_filter($result->rows, fn (array $row, string $target): bool => $row['status'] === DiskMeasureStatus::Ok || ! isset($kept[$target]) || (int) $kept[$target] <= $row['bytes'], ARRAY_FILTER_USE_BOTH);

            DiskSnapshot::query()->upsert(
                array_map(fn (string $target, array $row): array => [
                    'target' => $target,
                    'taken_on' => $day,
                    'size_bytes' => $row['bytes'],
                    'file_count' => min($row['files'], 4_294_967_295),
                    'status' => $row['status']->value,
                    'duration_ms' => min($row['ms'], 4_294_967_295),
                    'measured_at' => $now,
                ], array_keys($rows), array_values($rows)),
                ['target', 'taken_on'],
                ['size_bytes', 'file_count', 'status', 'duration_ms', 'measured_at'],
            );

            return true;
        });
    }

    /**
     * Failed with an error code only; the exception's class is logged, never its message (it may hold a path).
     */
    private function failRun(?Throwable $exception): void
    {
        if ($exception !== null) {
            Log::warning('Project size measurement failed', ['exception' => $exception::class]);
        }

        $runId = $this->runId;

        if ($runId === null && $this->job?->uuid() !== null) {
            $stored = Cache::get(self::JOB_RUN_PREFIX.$this->job->uuid());
            $runId = is_string($stored) ? $stored : null;
        }

        DiskRun::fail($runId, DiskRunError::Exception);
    }
}
