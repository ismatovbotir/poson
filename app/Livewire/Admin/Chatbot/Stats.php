<?php

namespace App\Livewire\Admin\Chatbot;

use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Models\ChatRule;
use App\Models\ChatStatDaily;
use App\Models\ChatUnmatched;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Chat bot → Stats (plan 28 C2, chatbot.rules): the daily counters of the
 * last 7, 30 or 90 days. Totals per metric and channel, the rules that
 * answered most, and the number of phrases waiting to be taught. Counts only.
 */
#[Layout('layouts::admin')]
class Stats extends Component
{
    public const RANGES = [7, 30, 90];

    #[Url(except: 30)]
    public int $days = 30;

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

        $days = in_array($this->days, self::RANGES, true) ? $this->days : 30;
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
        ])->title(__('chatbot_admin.stats.title'));
    }
}
