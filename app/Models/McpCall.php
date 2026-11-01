<?php

namespace App\Models;

use App\Enums\McpAuthType;
use App\Enums\McpCallStatus;
use App\Enums\McpDenyReason;
use Database\Factories\McpCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One MCP request in the audit log (plan 13 §4, C10/C17/C22): every tool
 * call, plus requests refused by EnsureMcpUser, a revoked token or the
 * IP allow-list, and rate-limited ones. Written only by
 * App\Actions\Mcp\RecordMcpCall; arguments are redacted (never a token,
 * C27). Rows older than KEEP_DAYS days are pruned (`model:prune`, daily).
 *
 * @property int $id
 * @property int|null $user_id
 * @property McpAuthType $auth_type
 * @property int|null $api_token_id
 * @property string|null $client_name
 * @property string $tool
 * @property bool $is_write
 * @property bool $is_publish
 * @property McpCallStatus $status
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $arguments
 * @property array<string, mixed>|null $before
 * @property int $duration_ms
 * @property string|null $ip
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'auth_type', 'api_token_id', 'client_name', 'tool', 'is_write', 'is_publish', 'status', 'subject_type', 'subject_id', 'arguments', 'before', 'duration_ms', 'ip'])]
class McpCall extends Model
{
    /** @use HasFactory<McpCallFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /**
     * Audit rows are kept this many days (C22).
     */
    public const KEEP_DAYS = 365;

    /**
     * Tool name used for requests refused before any tool ran.
     */
    public const REQUEST_TOOL = '(request)';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auth_type' => McpAuthType::class,
            'is_write' => 'boolean',
            'is_publish' => 'boolean',
            'status' => McpCallStatus::class,
            'arguments' => 'array',
            'before' => 'array',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Rows older than KEEP_DAYS days.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * Why a denied request was refused (denied rows only).
     */
    public function denyReason(): ?McpDenyReason
    {
        $reason = $this->arguments['reason'] ?? null;

        return is_string($reason) ? McpDenyReason::tryFrom($reason) : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ApiToken, $this>
     */
    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }

    /**
     * The record a write tool changed (posts, products, FAQ, pages: M3+).
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
