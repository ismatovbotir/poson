<?php

namespace App\Actions\Chat;

use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Models\ChatAiUsageDaily;
use App\Services\Ai\AiResult;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\WorkingHours;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The AI daily caps and usage counters (plan 30 §8, AI-18), per
 * Asia/Tashkent day and channel, in chat_ai_usage_daily. Counts and tokens
 * only, never text. Every statement uses bindings (no SQL built from values).
 *
 *  - reserve(): right before an API call, ONE conditional update both checks
 *    and takes the reservation (requests < cap AND billed + reserved +
 *    estimate <= token cap), so two concurrent reservations at cap - 1 give
 *    one call. The row is created first (insertOrIgnore). The estimate is
 *    AiRequest::estimatedBilledTokens (cached prefix at a tenth), so the
 *    default 3,000,000 tokens cover the default 1,500 requests.
 *  - settle(): after the call, the reservation is released (clamped at 0)
 *    and the real usage (AiResult::billedTokens) added, with the outcome counter.
 *  - capped(): a request refused by a limit or cap (no call).
 *  - withinCaps(): a read-only check for the webhook (P3); the reservation
 *    in the job is what enforces the cap.
 */
class RecordAiUsage
{
    public static function day(): string
    {
        return now(WorkingHours::TIMEZONE)->toDateString();
    }

    public function withinCaps(ChatChannel $channel): bool
    {
        $caps = ChatSettings::all()['ai'];
        $row = $this->row($channel, self::day())->first(['requests', 'billed_tokens', 'reserved_tokens']);

        if ($row === null) {
            return $caps['daily_requests'] > 0 && $caps['daily_tokens'] > 0;
        }

        return $row->requests < $caps['daily_requests'] && $row->billed_tokens + $row->reserved_tokens < $caps['daily_tokens'];
    }

    /**
     * @return string|null The day the reservation was taken on (pass it to settle()), or null when a cap is reached
     */
    public function reserve(ChatChannel $channel, int $tokens): ?string
    {
        $day = self::day();
        $caps = ChatSettings::all()['ai'];
        $tokens = max(0, $tokens);

        $this->ensureRow($channel, $day);

        $updated = $this->row($channel, $day)
            ->where('requests', '<', $caps['daily_requests'])
            ->whereRaw('billed_tokens + reserved_tokens + ? <= ?', [$tokens, $caps['daily_tokens']])
            ->incrementEach(['requests' => 1, 'reserved_tokens' => $tokens]);

        return $updated === 1 ? $day : null;
    }

    public function settle(ChatChannel $channel, string $day, int $reserved, ChatAiStatus $status, ?AiResult $result): void
    {
        $reserved = max(0, $reserved);

        if ($reserved > 0) {
            // One statement, clamped at 0 (unsigned column), values bound.
            DB::update(
                'update chat_ai_usage_daily set reserved_tokens = case when reserved_tokens > ? then reserved_tokens - ? else 0 end where day = ? and channel = ?',
                [$reserved, $reserved, $day, $channel->value],
            );
        }

        $amounts = $result === null ? [] : [
            'input_tokens' => max(0, $result->inputTokens),
            'output_tokens' => max(0, $result->outputTokens),
            'cache_read_tokens' => max(0, $result->cacheReadTokens),
            'cache_write_tokens' => max(0, $result->cacheWriteTokens),
            'billed_tokens' => max(0, $result->billedTokens()),
        ];

        $counter = $this->counter($status);

        if ($counter !== null) {
            $amounts[$counter] = 1;
        }

        if ($amounts !== []) {
            $this->row($channel, $day)->incrementEach($amounts);
        }
    }

    public function capped(ChatChannel $channel): void
    {
        $day = self::day();
        $this->ensureRow($channel, $day);

        $this->row($channel, $day)->increment('capped');
    }

    private function counter(ChatAiStatus $status): ?string
    {
        return match ($status) {
            ChatAiStatus::Answered, ChatAiStatus::Truncated => 'answered',
            ChatAiStatus::Failed => 'failed',
            ChatAiStatus::Refused => 'refused',
            ChatAiStatus::Blocked => 'blocked',
            ChatAiStatus::Capped => 'capped',
            ChatAiStatus::Superseded, ChatAiStatus::Disabled => null,
        };
    }

    /**
     * @return Builder<ChatAiUsageDaily>
     */
    private function row(ChatChannel $channel, string $day): Builder
    {
        return ChatAiUsageDaily::query()->where('day', $day)->where('channel', $channel->value);
    }

    private function ensureRow(ChatChannel $channel, string $day): void
    {
        ChatAiUsageDaily::query()->insertOrIgnore(['day' => $day, 'channel' => $channel->value]);
    }
}
