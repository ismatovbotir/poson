<?php

namespace App\Models;

use Database\Factories\MonitoringStateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Heartbeat / snapshot / alert state of the monitor (plan 17 §1), keyed by
 * name: heartbeat:scheduler, heartbeat:worker, schedule:snapshot,
 * problem:cron_dead, problem:queue_stuck, problem:worker_silent,
 * problem:task:{md5(task)}.
 *
 * @property string $key
 * @property Carbon|null $ticked_at
 * @property Carbon|null $problem_since
 * @property Carbon|null $alerted_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'ticked_at', 'problem_since', 'alerted_at', 'meta'])]
class MonitoringState extends Model
{
    /** @use HasFactory<MonitoringStateFactory> */
    use HasFactory;

    public const CREATED_AT = null;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'ticked_at' => 'datetime',
            'problem_since' => 'datetime',
            'alerted_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
