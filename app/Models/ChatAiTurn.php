<?php

namespace App\Models;

use App\Enums\ChatAiRole;
use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use Database\Factories\ChatAiTurnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One turn of an AI conversation in the chat bot (plan 30 §3). A user row is
 * the customer's message after ChatRedactor (never the raw text); an
 * assistant row answers exactly one user row (answers_turn_id, unique) and
 * holds the filtered answer, its status and usage. No prompt is stored, only
 * its hash. Deleted with the conversation; kept 30 days. Never exposed to
 * MCP (AI-23).
 *
 * @property int $id
 * @property ChatChannel $channel
 * @property int|null $telegram_user_id
 * @property int|null $web_chat_id
 * @property int|null $answers_turn_id
 * @property ChatAiRole $role
 * @property string $text
 * @property ChatAiStatus|null $status
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string|null $stop_reason
 * @property string|null $prompt_hash
 * @property list<int>|null $product_ids
 * @property int|null $latency_ms
 * @property Carbon $created_at
 */
#[Fillable([
    'channel', 'telegram_user_id', 'web_chat_id', 'answers_turn_id', 'role', 'text', 'status', 'model',
    'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'stop_reason', 'prompt_hash',
    'product_ids', 'latency_ms',
])]
class ChatAiTurn extends Model
{
    /** @use HasFactory<ChatAiTurnFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChatChannel::class,
            'role' => ChatAiRole::class,
            'status' => ChatAiStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'product_ids' => 'array',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramUser, $this>
     */
    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    /**
     * The assistant row that answers this user turn, if any.
     *
     * @return HasOne<ChatAiTurn, $this>
     */
    public function answer(): HasOne
    {
        return $this->hasOne(self::class, 'answers_turn_id');
    }
}
