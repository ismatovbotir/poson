<?php

namespace App\Models;

use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Enums\AiProvider;
use App\Enums\AiRequestStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AiRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One admin AI assist request (plan 02 §4). Created by the actions in
 * App\Actions\Ai, completed by the RunAiRequest job. Never holds keys,
 * the system prompt or raw provider errors: prompt is the user's final
 * hint, error_code a safe AiErrorCode.
 *
 * @property int $id
 * @property int|null $user_id
 * @property AiFeature $feature
 * @property AiProvider $provider
 * @property string|null $model
 * @property string|null $field_key
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $source_locale
 * @property AiRequestStatus $status
 * @property AiErrorCode|null $error_code
 * @property string|null $prompt
 * @property array<string, string>|null $response
 * @property array<string, list<string>>|null $brand_warnings
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $images
 * @property int $cost_micro_usd
 * @property int|null $duration_ms
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $pruned_at
 * @property Carbon $created_at
 */
#[Fillable([
    'user_id', 'feature', 'provider', 'model', 'field_key', 'subject_type', 'subject_id', 'source_locale',
    'status', 'error_code', 'prompt', 'response', 'brand_warnings',
    'input_tokens', 'output_tokens', 'images', 'cost_micro_usd', 'duration_ms',
    'started_at', 'finished_at', 'pruned_at',
])]
class AiRequest extends Model
{
    /** @use HasFactory<AiRequestFactory> */
    use HasFactory;

    /**
     * Micro-USD per US dollar.
     */
    public const MICRO = 1_000_000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'provider' => AiProvider::class,
            'status' => AiRequestStatus::class,
            'error_code' => AiErrorCode::class,
            'response' => 'array',
            'brand_warnings' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'images' => 'integer',
            'cost_micro_usd' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Requests created since the given moment (UTC in the database).
     *
     * @param  Builder<self>  $query
     */
    public function scopeSince(Builder $query, CarbonImmutable $from): void
    {
        $query->where('created_at', '>=', $from->utc());
    }

    /**
     * Rows that count towards the quotas (not rejected).
     *
     * @param  Builder<self>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereIn('status', AiRequestStatus::counted());
    }

    /**
     * Cost in US dollars, for display.
     */
    public function costUsd(): float
    {
        return $this->cost_micro_usd / self::MICRO;
    }

    /**
     * Seconds after which an open request counts as timed out (FailStaleAiRequest):
     * the longest job run (2 × timeout + 15) plus slack for the worker.
     */
    public static function staleAfterSeconds(): int
    {
        return 2 * (int) config('ai-assist.timeout', 45) + 75;
    }
}
