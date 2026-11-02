<?php

namespace App\Models;

use App\Enums\TaskRunStatus;
use App\Enums\TaskRunTrigger;
use Database\Factories\ScheduledTaskRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of a scheduled task (plan 17 §1). Written by
 * App\Support\Monitoring\RunRecorder (scheduler events) and, later, by the
 * manual run job. error is the redacted first line; output the redacted last
 * 2 KB of a failed run. Rows older than KEEP_DAYS days are pruned.
 *
 * @property int $id
 * @property string $task
 * @property TaskRunTrigger $trigger
 * @property TaskRunStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $exit_code
 * @property string|null $error
 * @property string|null $output
 * @property int|null $user_id
 */
#[Fillable(['task', 'trigger', 'status', 'started_at', 'finished_at', 'duration_ms', 'exit_code', 'error', 'output', 'user_id'])]
class ScheduledTaskRun extends Model
{
    /** @use HasFactory<ScheduledTaskRunFactory> */
    use HasFactory, MassPrunable;

    public const KEEP_DAYS = 14;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'trigger' => TaskRunTrigger::class,
            'status' => TaskRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'exit_code' => 'integer',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('started_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
