<?php

namespace App\Models;

use App\Enums\AiDraftStatus;
use Database\Factories\AiProductDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One AI product wizard run (plan 32 §3, PW-18): create or improve, owned by
 * one user and always read by uuid + user_id (ownedBy). Status changes are
 * conditional UPDATEs (PW-8, App\Actions\Catalog\Ai\AiDraftState). Holds no
 * raw model answer, prompt, image bytes or base64; image refs are
 * server-side indexes or media ids (plan change 2). Never exposed to MCP.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $mode
 * @property int|null $product_id
 * @property AiDraftStatus $status
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $facts
 * @property array<string, mixed>|null $result
 * @property array<string, string>|null $snapshot
 * @property array<string, mixed>|null $apply_state
 * @property string|null $error
 * @property string|null $vision_model
 * @property string|null $write_model
 * @property int $vision_input_tokens
 * @property int $vision_output_tokens
 * @property int $write_input_tokens
 * @property int $write_output_tokens
 * @property int|null $vision_ms
 * @property int|null $write_ms
 * @property Carbon|null $applied_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property Carbon $expires_at
 */
#[Fillable([
    'uuid', 'user_id', 'mode', 'product_id', 'status', 'input', 'facts', 'result', 'snapshot', 'apply_state',
    'error', 'vision_model', 'write_model', 'vision_input_tokens', 'vision_output_tokens', 'write_input_tokens', 'write_output_tokens',
    'vision_ms', 'write_ms', 'applied_at', 'expires_at',
])]
class AiProductDraft extends Model
{
    /** @use HasFactory<AiProductDraftFactory> */
    use HasFactory;

    public const CREATE = 'create';

    public const IMPROVE = 'improve';

    public const MODES = [self::CREATE, self::IMPROVE];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AiDraftStatus::class,
            'input' => 'array',
            'facts' => 'array',
            'result' => 'array',
            'snapshot' => 'array',
            'apply_state' => 'array',
            'vision_input_tokens' => 'integer',
            'vision_output_tokens' => 'integer',
            'write_input_tokens' => 'integer',
            'write_output_tokens' => 'integer',
            'vision_ms' => 'integer',
            'write_ms' => 'integer',
            'applied_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user, string $uuid): void
    {
        $query->where('uuid', $uuid)->where('user_id', $user->id);
    }

    public function isImprove(): bool
    {
        return $this->mode === self::IMPROVE;
    }

    /**
     * The draft's private folder, relative to the local disk.
     */
    public static function folder(string $uuid): string
    {
        return 'ai-drafts/'.$uuid;
    }
}
