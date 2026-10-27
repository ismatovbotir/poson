<?php

namespace App\Models;

use App\Enums\PriceSyncStatus;
use Database\Factories\PriceSyncLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One price API batch (plan 09), written by App\Actions\Api\SyncPrices in
 * the batch's transaction. Append-only (no updated_at); pruned after
 * KEEP_DAYS days (`model:prune`, scheduled daily).
 *
 * results: one entry per received item, in request order:
 * {product_id?, code, status, errors?, old?, new?} where old / new are
 * {retail, dealer, wholesale} in tiyin (updated items only): the price history.
 *
 * @property int $id
 * @property int|null $api_token_id
 * @property string|null $token_name
 * @property string|null $ip
 * @property PriceSyncStatus $status
 * @property int $received
 * @property int $updated
 * @property int $unchanged
 * @property int $unknown
 * @property int $invalid
 * @property list<string>|null $unknown_codes
 * @property list<array<string, mixed>>|null $results
 * @property string|null $error
 * @property int|null $duration_ms
 * @property Carbon $created_at
 */
#[Fillable([
    'api_token_id', 'token_name', 'ip', 'status', 'received', 'updated', 'unchanged', 'unknown', 'invalid',
    'unknown_codes', 'results', 'error', 'duration_ms', 'created_at',
])]
class PriceSyncLog extends Model
{
    /** @use HasFactory<PriceSyncLogFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Days a log row is kept.
     */
    public const KEEP_DAYS = 180;

    /**
     * Times are shown in Tashkent time.
     */
    public const TIMEZONE = 'Asia/Tashkent';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PriceSyncStatus::class,
            'received' => 'integer',
            'updated' => 'integer',
            'unchanged' => 'integer',
            'unknown' => 'integer',
            'invalid' => 'integer',
            'unknown_codes' => 'array',
            'results' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Rows older than KEEP_DAYS days.
     *
     * @return Builder<PriceSyncLog>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @return BelongsTo<ApiToken, $this>
     */
    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }

    /**
     * Whether the batch had problems worth an alert (unknown / invalid items, or rejected).
     */
    public function hasIssues(): bool
    {
        return $this->status === PriceSyncStatus::Rejected || $this->unknown > 0 || $this->invalid > 0;
    }
}
