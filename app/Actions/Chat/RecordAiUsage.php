<?php

namespace App\Actions\Chat;

use App\Enums\AiUsageChannel;
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
 *
 * Channels (plan 32 §14 plan change 3, PW-12): AiUsageChannel, a chat
 * ChatChannel is mapped to it. caps() is per channel: the bot channels use
 * the chat caps (settings, default chatbot.ai.caps.requests/tokens), each
 * admin stage its own (chatbot.ai.caps.{value}, settings override), so an
 * admin stage never consumes a chat cap and the other way round.
 */
class RecordAiUsage
{
    public static function day(): string
    {
        return now(WorkingHours::TIMEZONE)->toDateString();
    }

    /**
     * Today's request and token caps of a usage channel.
     *
     * @return array{requests: int, tokens: int}
     */
    public static function caps(AiUsageChannel $channel): array
    {
        $ai = ChatSettings::all()['ai'];

        if (! $channel->isAdmin()) {
            return ['requests' => (int) $ai['daily_requests'], 'tokens' => (int) $ai['daily_tokens']];
        }

        $caps = $ai['caps'][$channel->value] ?? null;

        if (is_array($caps)) {
            return ['requests' => (int) $caps['requests'], 'tokens' => (int) $caps['tokens']];
        }

        return [
            'requests' => (int) config("chatbot.ai.caps.{$channel->value}.requests", 0),
            'tokens' => (int) config("chatbot.ai.caps.{$channel->value}.tokens", 0),
        ];
    }

    public function withinCaps(ChatChannel|AiUsageChannel $channel): bool
    {
        $channel = self::channel($channel);
        $caps = self::caps($channel);
        $row = $this->row($channel, self::day())->first(['requests', 'billed_tokens', 'reserved_tokens']);

        if ($row === null) {
            return $caps['requests'] > 0 && $caps['tokens'] > 0;
        }

        return $row->requests < $caps['requests'] && $row->billed_tokens + $row->reserved_tokens < $caps['tokens'];
    }

    /**
     * @return string|null The day the reservation was taken on (pass it to settle()), or null when a cap is reached
     */
    public function reserve(ChatChannel|AiUsageChannel $channel, int $tokens): ?string
    {
        $channel = self::channel($channel);
        $day = self::day();
        $caps = self::caps($channel);
        $tokens = max(0, $tokens);

        $this->ensureRow($channel, $day);

        $updated = $this->row($channel, $day)
            ->where('requests', '<', $caps['requests'])
            ->whereRaw('billed_tokens + reserved_tokens + ? <= ?', [$tokens, $caps['tokens']])
            ->incrementEach(['requests' => 1, 'reserved_tokens' => $tokens]);

        return $updated === 1 ? $day : null;
    }

    public function settle(ChatChannel|AiUsageChannel $channel, string $day, int $reserved, ChatAiStatus $status, ?AiResult $result): void
    {
        $channel = self::channel($channel);
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

    public function capped(ChatChannel|AiUsageChannel $channel): void
    {
        $channel = self::channel($channel);
        $day = self::day();
        $this->ensureRow($channel, $day);

        $this->row($channel, $day)->increment('capped');
    }

    private static function channel(ChatChannel|AiUsageChannel $channel): AiUsageChannel
    {
        return $channel instanceof ChatChannel ? AiUsageChannel::fromChatChannel($channel) : $channel;
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
    private function row(AiUsageChannel $channel, string $day): Builder
    {
        return ChatAiUsageDaily::query()->where('day', $day)->where('channel', $channel->value);
    }

    private function ensureRow(AiUsageChannel $channel, string $day): void
    {
        ChatAiUsageDaily::query()->insertOrIgnore(['day' => $day, 'channel' => $channel->value]);
    }
}
