<?php

namespace App\Actions\Chat;

use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Models\ChatStatDaily;
use Illuminate\Support\Facades\DB;

/**
 * Count one chat event in chat_stats_daily (plan 28): per day, channel,
 * metric and rule (0 = no rule). Counts only, never text. One upsert: the row is created
 * on first use and incremented atomically in the database.
 */
class RecordChatStat
{
    public function handle(ChatChannel $channel, ChatMetric $metric, int $ruleId = 0): void
    {
        $count = DB::connection()->getQueryGrammar()->wrap('count');

        ChatStatDaily::query()->upsert(
            [[
                'day' => today()->toDateString(),
                'channel' => $channel->value,
                'metric' => $metric->value,
                'chat_rule_id' => max(0, $ruleId),
                'count' => 1,
            ]],
            ['day', 'channel', 'metric', 'chat_rule_id'],
            ['count' => DB::raw("{$count} + 1")],
        );
    }
}
