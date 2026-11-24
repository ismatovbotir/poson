<?php

namespace App\Models;

use App\Enums\DiskMeasureStatus;
use App\Enums\DiskTarget;
use Database\Factories\DiskSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One measurement of the project folder per target and Tashkent day (plan
 * 37). Written only by App\Jobs\MeasureProjectSize (upsert by target and
 * day); the page reads it. Policy-free: access is the monitoring permission.
 * Rows older than KEEP_DAYS days are pruned (`model:prune`, 02:55).
 *
 * @property int $id
 * @property DiskTarget $target
 * @property Carbon $taken_on
 * @property int|null $size_bytes
 * @property int|null $file_count
 * @property DiskMeasureStatus $status
 * @property int $duration_ms
 * @property Carbon|null $measured_at
 */
#[Fillable(['target', 'taken_on', 'size_bytes', 'file_count', 'status', 'duration_ms', 'measured_at'])]
class DiskSnapshot extends Model
{
    /** @use HasFactory<DiskSnapshotFactory> */
    use HasFactory, MassPrunable;

    public const KEEP_DAYS = 400;

    public $timestamps = false;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'target' => DiskTarget::class,
            'taken_on' => 'date',
            'size_bytes' => 'integer',
            'file_count' => 'integer',
            'status' => DiskMeasureStatus::class,
            'duration_ms' => 'integer',
            'measured_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('taken_on', '<', now('Asia/Tashkent')->subDays(self::KEEP_DAYS)->toDateString());
    }
}
