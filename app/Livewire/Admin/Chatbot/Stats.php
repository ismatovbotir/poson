<?php

namespace App\Livewire\Admin\Chatbot;

use App\Enums\AiUsageChannel;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Models\ChatAiUsageDaily;
use App\Models\ChatRule;
use App\Models\ChatStatDaily;
use App\Models\ChatUnmatched;
use App\Support\Chat\WorkingHours;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Chat bot → Stats (plan 28 C2, chatbot.rules): the daily counters of the
 * last 7, 30 or 90 days. Totals per metric and channel, the rules that
 * answered most, and the number of phrases waiting to be taught. Counts only.
 * AI usage (plan 30 §9, phase B): chat_ai_usage_daily per day and
 * AiUsageChannel (the chat bot channels and the admin helpers such as
 * "Translate with Claude") with an estimated cost in USD (config
 * chatbot.ai.prices). Plan 32: the admin product wizard stages
 * (adm_vision / adm_write) are priced at their configured model
 * (chatbot.ai.product.prices); a bot channel filter shows that channel only.
 */
#[Layout('layouts::admin')]
class Stats extends Component
{
    public const RANGES = [7, 30, 90];

    #[Url(except: '30')]
    public string $days = '30';

    #[Url(except: '')]
    public string $channel = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ChatRule::class);
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', ChatRule::class);
    }

    public function render(): View
    {
        $this->authorize('viewAny', ChatRule::class);

        $days = in_array((int) $this->days, self::RANGES, true) ? (int) $this->days : 30;
        $channel = ChatChannel::tryFrom($this->channel);
        $from = today()->subDays($days - 1)->toDateString();

        $base = fn (): Builder => ChatStatDaily::query()
            ->where('day', '>=', $from)
            ->when($channel, fn (Builder $query, ChatChannel $channel) => $query->where('channel', $channel->value));

        $totals = $base()->selectRaw('metric, SUM(count) as total')->groupBy('metric')->pluck('total', 'metric');

        $byChannel = $base()->selectRaw('channel, metric, SUM(count) as total')->groupBy('channel', 'metric')->get()
            ->groupBy(fn (ChatStatDaily $row): string => $row->channel->value)
            ->map(fn ($rows) => $rows->mapWithKeys(fn (ChatStatDaily $row): array => [$row->metric->value => (int) $row->total]));

        $perRule = $base()->where('metric', ChatMetric::Hit->value)->where('chat_rule_id', '>', 0)
            ->selectRaw('chat_rule_id, SUM(count) as total')->groupBy('chat_rule_id')->orderByDesc('total')->limit(20)->get();

        $names = ChatRule::query()->whereIn('id', $perRule->pluck('chat_rule_id'))->pluck('name', 'id');

        return view('livewire.admin.chatbot.stats', [
            'rangeDays' => $days,
            'totals' => $totals,
            'byChannel' => $byChannel,
            'perRule' => $perRule->map(fn (ChatStatDaily $row): array => [
                'name' => $names[$row->chat_rule_id] ?? __('chatbot_admin.stats.deleted_rule'),
                'total' => (int) $row->total,
            ]),
            'waiting' => ChatUnmatched::query()->whereNull('resolved_at')->count(),
            'aiRows' => $this->aiRows($days, $channel),
        ])->title(__('chatbot_admin.stats.title'));
    }

    /**
     * AI usage rows of the period (Asia/Tashkent days), newest first, with the estimated cost.
     *
     * @return list<array<string, mixed>>
     */
    private function aiRows(int $days, ?ChatChannel $channel): array
    {
        $from = now(WorkingHours::TIMEZONE)->subDays($days - 1)->toDateString();

        return ChatAiUsageDaily::query()
            ->where('day', '>=', $from)
            ->when($channel, fn (Builder $query, ChatChannel $channel) => $query->where('channel', AiUsageChannel::fromChatChannel($channel)->value))
            ->whereIn('channel', array_column(AiUsageChannel::cases(), 'value'))
            ->orderByDesc('day')
            ->orderBy('channel')
            // At most 90 days x every usage channel: one aggregated row per day and channel.
            ->limit(count(AiUsageChannel::cases()) * max(self::RANGES))
            ->get()
            ->map(function (ChatAiUsageDaily $row): array {
                return [
                    'day' => $row->day->toDateString(),
                    'channel' => $row->channel->label(),
                    'requests' => $row->requests,
                    'answered' => $row->answered,
                    'refused' => $row->refused,
                    'blocked' => $row->blocked,
                    'failed' => $row->failed,
                    'capped' => $row->capped,
                    'input_tokens' => $row->input_tokens,
                    'output_tokens' => $row->output_tokens,
                    'cache_read_tokens' => $row->cache_read_tokens,
                    'cache_write_tokens' => $row->cache_write_tokens,
                    'usd' => self::usd($row->channel, $row->input_tokens, $row->output_tokens, $row->cache_read_tokens, $row->cache_write_tokens),
                ];
            })
            ->all();
    }

    /**
     * Estimated USD of a channel's tokens (prices per million tokens of its model).
     */
    public static function usd(AiUsageChannel $channel, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $prices = self::prices($channel);

        return ($input * (float) ($prices['input'] ?? 0)
            + $output * (float) ($prices['output'] ?? 0)
            + $cacheRead * (float) ($prices['cache_read'] ?? 0)
            + $cacheWrite * (float) ($prices['cache_write'] ?? 0)) / 1_000_000;
    }

    /**
     * USD per million tokens of the model a usage channel runs on.
     *
     * @return array<string, float|int>
     */
    public static function prices(AiUsageChannel $channel): array
    {
        $stage = match ($channel) {
            AiUsageChannel::AdminVision => 'vision',
            AiUsageChannel::AdminWrite => 'write',
            default => null,
        };

        if ($stage === null) {
            return (array) config('chatbot.ai.prices');
        }

        $model = (string) config("chatbot.ai.product.{$stage}.model");

        return (array) config("chatbot.ai.product.prices.{$model}", []);
    }
}
