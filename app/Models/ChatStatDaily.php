<?php

namespace App\Models;

use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use Database\Factories\ChatStatDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily counter of the chat bot (plan 28): per day, channel, metric and rule
 * (0 = no rule). Counts only. Written only by RecordChatStat.
 *
 * @property int $id
 * @property Carbon $day
 * @property ChatChannel $channel
 * @property ChatMetric $metric
 * @property int $chat_rule_id
 * @property int $count
 */
#[Fillable(['day', 'channel', 'metric', 'chat_rule_id', 'count'])]
class ChatStatDaily extends Model
{
    /** @use HasFactory<ChatStatDailyFactory> */
    use HasFactory;

    protected $table = 'chat_stats_daily';

    public $timestamps = false;

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
            'metric' => ChatMetric::class,
            'chat_rule_id' => 'integer',
            'count' => 'integer',
        ];
    }
}
