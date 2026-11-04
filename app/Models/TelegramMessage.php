<?php

namespace App\Models;

use App\Enums\TelegramDirection;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use Database\Factories\TelegramMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One bot message (plan 14 v2): text, or metadata only for media (the file is
 * copied to the team chat, never stored). Removed with its conversation
 * (cascade), so there is no separate prune: threads with a lead-linked
 * message are kept by TelegramUser::prunable(). Never exposed to MCP (TG-36).
 *
 * @property int $id
 * @property int $telegram_user_id
 * @property TelegramDirection $direction
 * @property TelegramMessageKind $kind
 * @property string|null $text
 * @property array<string, mixed>|null $media
 * @property string|null $media_group_id
 * @property int|null $telegram_message_id
 * @property int|null $user_id
 * @property TelegramMessageStatus $status
 * @property int|null $error_code
 * @property Carbon|null $sent_at
 * @property TelegramForwardStatus|null $forward_status
 * @property int|null $forwarded_message_id
 * @property int|null $forward_error_code
 * @property int|null $lead_id
 */
#[Fillable([
    'telegram_user_id', 'direction', 'kind', 'text', 'media', 'media_group_id',
    'telegram_message_id', 'user_id', 'status', 'error_code', 'sent_at',
    'forward_status', 'forwarded_message_id', 'forward_error_code',
])]
class TelegramMessage extends Model
{
    /** @use HasFactory<TelegramMessageFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TelegramDirection::class,
            'kind' => TelegramMessageKind::class,
            'status' => TelegramMessageStatus::class,
            'forward_status' => TelegramForwardStatus::class,
            'media' => 'array',
            'telegram_message_id' => 'integer',
            'error_code' => 'integer',
            'forwarded_message_id' => 'integer',
            'forward_error_code' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * A claimed send or forward older than this many minutes is "stuck" (the
     * worker died mid-call); only an explicit staff retry reclaims it.
     */
    public const STUCK_MINUTES = 10;

    /**
     * Rows left in "sending" (delivery or forward) for more than STUCK_MINUTES
     * (B4-S11c), for the inbox (B5) and RetryForward.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStuck(Builder $query): void
    {
        $query->where('telegram_messages.updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->where(fn (Builder $inner) => $inner
                ->where('telegram_messages.status', TelegramMessageStatus::Sending)
                ->orWhere('telegram_messages.forward_status', TelegramForwardStatus::Sending));
    }

    /**
     * @return BelongsTo<TelegramUser, $this>
     */
    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    /**
     * Staff author of an outbound message.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
