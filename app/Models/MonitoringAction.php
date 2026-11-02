<?php

namespace App\Models;

use App\Enums\MonitoringAction as MonitoringActionType;
use Database\Factories\MonitoringActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit row for an admin action on the monitoring screen (plan 17, MON-S9).
 * target is one task key or a JSON list of failed-job uuids (TEXT); never a
 * payload. Pruned after KEEP_DAYS days.
 *
 * @property int $id
 * @property int|null $user_id
 * @property MonitoringActionType $action
 * @property string $target
 * @property string|null $job_class
 * @property int $count
 * @property string|null $ip
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'action', 'target', 'job_class', 'count', 'ip'])]
class MonitoringAction extends Model
{
    /** @use HasFactory<MonitoringActionFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    public const KEEP_DAYS = 365;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'action' => MonitoringActionType::class,
            'count' => 'integer',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
