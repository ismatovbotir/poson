<?php

namespace App\Support\Monitoring;

use App\Enums\DiskRunError;
use App\Enums\DiskRunStatus;
use App\Enums\DiskTarget;
use App\Models\MonitoringState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Progress of the project size measurement (plan 37, DM-26, DM-27): the
 * monitoring_states row "disk:progress" with a fixed meta shape
 *
 *   {run_id, status, bucket, files, bytes, started_at, heartbeat_at, finished_at, error_code}
 *
 * Labels (enums) and numbers only, never a path, a name or a message. Every
 * read validates the shape (a tampered value falls back to null / 0) and
 * works out a stale run on read: running with a heartbeat older than 60 s or
 * past started_at + 360 s ("worker stopped"), queued for over 15 minutes
 * ("worker not running"). Every write is conditional on the run_id.
 */
final class DiskRun
{
    public const KEY = 'disk:progress';

    public const HEARTBEAT_STALE = 60;

    public const RUN_MAX = 360;

    public const QUEUED_MAX = 900;

    /**
     * No new start this long after a finished run (DM-17) or a failed one.
     */
    public const DONE_COOLDOWN = 900;

    public const FAILED_COOLDOWN = 300;

    public function __construct(
        public readonly DiskRunStatus $status = DiskRunStatus::Idle,
        public readonly ?string $runId = null,
        public readonly ?DiskTarget $bucket = null,
        public readonly int $files = 0,
        public readonly int $bytes = 0,
        public readonly ?Carbon $startedAt = null,
        public readonly ?Carbon $heartbeatAt = null,
        public readonly ?Carbon $finishedAt = null,
        public readonly ?DiskRunError $error = null,
        public readonly bool $stale = false,
    ) {}

    public static function newRunId(): string
    {
        return Str::lower(Str::random(32));
    }

    /**
     * One query.
     */
    public static function current(): self
    {
        return self::fromState(MonitoringState::query()->find(self::KEY));
    }

    public static function fromState(?MonitoringState $state): self
    {
        $meta = $state?->meta;

        if (! is_array($meta)) {
            return new self;
        }

        $runId = $meta['run_id'] ?? null;
        $run = new self(
            status: DiskRunStatus::tryFrom((string) ($meta['status'] ?? '')) ?? DiskRunStatus::Idle,
            runId: is_string($runId) && preg_match('/^[a-z0-9]{32}$/', $runId) ? $runId : null,
            bucket: DiskTarget::tryFrom((string) ($meta['bucket'] ?? '')),
            files: max(0, (int) ($meta['files'] ?? 0)),
            bytes: max(0, (int) ($meta['bytes'] ?? 0)),
            startedAt: self::date($meta['started_at'] ?? null),
            heartbeatAt: self::date($meta['heartbeat_at'] ?? null),
            finishedAt: self::date($meta['finished_at'] ?? null),
            error: DiskRunError::tryFrom((string) ($meta['error_code'] ?? '')),
        );

        return $run->status->isActive() ? $run->withStale() : $run;
    }

    private function withStale(): self
    {
        $now = now();
        $stale = false;
        $error = null;

        if ($this->runId === null || $this->startedAt === null) {
            $stale = true;
            $error = DiskRunError::WorkerLost;
        } elseif ($this->status === DiskRunStatus::Queued) {
            $stale = $this->startedAt->diffInSeconds($now, true) > self::QUEUED_MAX;
            $error = DiskRunError::WorkerMissing;
        } else {
            $heartbeat = $this->heartbeatAt ?? $this->startedAt;
            $stale = $heartbeat->diffInSeconds($now, true) > self::HEARTBEAT_STALE
                || $this->startedAt->diffInSeconds($now, true) > self::RUN_MAX;
            $error = DiskRunError::WorkerLost;
        }

        if (! $stale) {
            return $this;
        }

        return new self(DiskRunStatus::Failed, $this->runId, $this->bucket, $this->files, $this->bytes, $this->startedAt, $this->heartbeatAt, $now, $error, true);
    }

    /**
     * Queued or running and not stale.
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Idle, or the last run is old enough that a new start is allowed (DM-17, DM-25).
     */
    public function mayStart(): bool
    {
        if ($this->isActive()) {
            return false;
        }

        if ($this->stale) {
            return true;
        }

        $age = $this->finishedAt === null ? null : $this->finishedAt->diffInSeconds(now(), true);

        return match ($this->status) {
            DiskRunStatus::Done => $age === null || $age >= self::DONE_COOLDOWN,
            DiskRunStatus::Failed => $age === null || $age >= self::FAILED_COOLDOWN,
            default => true,
        };
    }

    /**
     * Seconds until a new start is allowed (0 when it is).
     */
    public function cooldownLeft(): int
    {
        if ($this->mayStart() || $this->isActive() || $this->finishedAt === null) {
            return 0;
        }

        $limit = $this->status === DiskRunStatus::Done ? self::DONE_COOLDOWN : self::FAILED_COOLDOWN;

        return max(0, $limit - (int) $this->finishedAt->diffInSeconds(now(), true));
    }

    /**
     * The fixed meta shape.
     *
     * @return array<string, mixed>
     */
    public static function meta(
        string $runId,
        DiskRunStatus $status,
        Carbon $startedAt,
        ?DiskTarget $bucket = null,
        int $files = 0,
        int $bytes = 0,
        ?Carbon $finishedAt = null,
        ?DiskRunError $error = null,
    ): array {
        return [
            'run_id' => $runId,
            'status' => $status->value,
            'bucket' => $bucket?->value,
            'files' => $files,
            'bytes' => $bytes,
            'started_at' => $startedAt->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
            'finished_at' => $finishedAt?->toIso8601String(),
            'error_code' => $error?->value,
        ];
    }

    /**
     * Write meta only if the row still belongs to this run in one of the given
     * states (DM-27). Returns whether it did (false: superseded or ended).
     *
     * Coupled to the affected-row count: MySQL reports 0 for an UPDATE that
     * changes nothing, so every write must change the row. updated_at and
     * heartbeat_at (seconds) do that; do not add a write that can repeat an
     * identical meta within the same second, or it reads as "superseded".
     *
     * @param  list<DiskRunStatus>  $from
     * @param  array<string, mixed>  $meta
     */
    public static function write(string $runId, array $from, array $meta): bool
    {
        return DB::table('monitoring_states')
            ->where('key', self::KEY)
            ->where('meta->run_id', $runId)
            ->whereIn('meta->status', array_map(fn (DiskRunStatus $status): string => $status->value, $from))
            ->update(['meta' => json_encode($meta), 'updated_at' => now()]) === 1;
    }

    /**
     * Mark a run failed, only if it is still this one and not finished.
     */
    public static function fail(?string $runId, DiskRunError $error): bool
    {
        if ($runId === null) {
            return false;
        }

        try {
            $current = self::fromState(MonitoringState::query()->find(self::KEY));
            $startedAt = $current->runId === $runId ? ($current->startedAt ?? now()) : now();

            return self::write($runId, [DiskRunStatus::Queued, DiskRunStatus::Running], self::meta($runId, DiskRunStatus::Failed, $startedAt, finishedAt: now(), error: $error));
        } catch (Throwable) {
            return false;
        }
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
