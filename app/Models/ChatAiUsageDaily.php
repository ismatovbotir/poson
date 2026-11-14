<?php

namespace App\Models;

use App\Enums\ChatChannel;
use Database\Factories\ChatAiUsageDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * AI usage of the chat bot per Asia/Tashkent day and channel (plan 30,
 * AI-18). Counts and tokens only. Written only by RecordAiUsage (atomic
 * reservations and settlements); never exposed to MCP (AI-23).
 *
 * @property int $id
 * @property Carbon $day
 * @property ChatChannel $channel
 * @property int $requests
 * @property int $answered
 * @property int $failed
 * @property int $refused
 * @property int $blocked
 * @property int $capped
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $billed_tokens
 * @property int $reserved_tokens
 */
#[Fillable([
    'day', 'channel', 'requests', 'answered', 'failed', 'refused', 'blocked', 'capped',
    'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'billed_tokens', 'reserved_tokens',
])]
class ChatAiUsageDaily extends Model
{
    /** @use HasFactory<ChatAiUsageDailyFactory> */
    use HasFactory;

    protected $table = 'chat_ai_usage_daily';

    public $timestamps = false;

    /**
     * "day" is a DATE column: stored as Y-m-d on every driver (the caps match on it).
     */
    protected $dateFormat = 'Y-m-d';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'channel' => ChatChannel::class,
            'requests' => 'integer',
            'answered' => 'integer',
            'failed' => 'integer',
            'refused' => 'integer',
            'blocked' => 'integer',
            'capped' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'billed_tokens' => 'integer',
            'reserved_tokens' => 'integer',
        ];
    }
}
