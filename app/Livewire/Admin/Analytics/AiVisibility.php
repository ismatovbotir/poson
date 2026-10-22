<?php

namespace App\Livewire\Admin\Analytics;

use App\Enums\TrafficChannel;
use App\Livewire\Admin\Concerns\WithAnalyticsRange;
use App\Models\TrafficDaily;
use App\Support\Analytics\BotRegistry;
use App\Support\Analytics\TrafficReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Analytics → AI visibility (/admin/analytics/ai, plan 04 T11), Admin only
 * through analytics.view. Per crawler: hits per day, verified / unverified /
 * spoofed, last seen, errors; top crawled pages and products, the 404 list
 * (all crawlers or one); and visitors and leads from AI assistants compared
 * with search engines and everything else.
 */
#[Layout('layouts::admin')]
class AiVisibility extends Component
{
    use WithAnalyticsRange;

    /**
     * Crawler key from config/ai-bots.php, '' for all.
     */
    #[Url]
    public string $bot = '';

    public function mount(): void
    {
        $this->normalizeBot();
    }

    public function updatedBot(): void
    {
        $this->normalizeBot();
    }

    public function render(): View
    {
        $report = $this->report();
        $bot = $this->bot !== '' ? $this->bot : null;

        return view('livewire.admin.analytics.ai-visibility', [
            'ranges' => TrafficReport::RANGES,
            'bots' => $report->bots(),
            'trackable' => BotRegistry::trackable(),
            'selected' => BotRegistry::find($bot),
            'pages' => $report->topPages(TrafficDaily::KIND_BOTS, $bot),
            'products' => $report->topProducts(TrafficDaily::KIND_BOTS, $bot),
            'notFound' => $report->topPages(TrafficDaily::KIND_BOTS, $bot, limit: 20, errorsOnly: true),
            'comparison' => $report->aiVersusSearch(),
            'aiSources' => $report->sources(10, [TrafficChannel::Ai]),
        ])->title(__('analytics.ai.title'));
    }

    protected function normalizeBot(): void
    {
        if ($this->bot !== '' && ! array_key_exists($this->bot, BotRegistry::trackable())) {
            $this->bot = '';
        }
    }
}
