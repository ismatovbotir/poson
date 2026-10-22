<?php

namespace App\Livewire\Admin\Analytics;

use App\Livewire\Admin\Concerns\WithAnalyticsRange;
use App\Models\TrafficDaily;
use App\Support\Analytics\TrafficReport;
use App\Support\CompanySettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Analytics → Traffic (/admin/analytics, plan 04 T11), Admin only through
 * analytics.view. First-party numbers from the daily aggregates: KPIs, a
 * server-rendered SVG chart, channels and sources with leads, top pages and
 * products, partner clicks ("Try" redirects) per product, devices and
 * languages, plus links out to GA4 / Metrika.
 */
#[Layout('layouts::admin')]
class Traffic extends Component
{
    use WithAnalyticsRange;

    public function render(CompanySettings $settings): View
    {
        $report = $this->report();
        $tracking = $settings->tracking();

        return view('livewire.admin.analytics.traffic', [
            'ranges' => TrafficReport::RANGES,
            'totals' => $report->totals(),
            'series' => $report->series(),
            'channels' => $report->channels(),
            'sources' => $report->sources(),
            'pages' => $report->topPages(TrafficDaily::KIND_VISITS),
            'products' => $report->topProducts(TrafficDaily::KIND_VISITS),
            'partnerClicks' => $report->partnerClicks(),
            'devices' => $report->breakdown(TrafficDaily::DIM_DEVICE),
            'locales' => $report->breakdown(TrafficDaily::DIM_LOCALE),
            'ga4Url' => $tracking['ga4'] !== '' ? 'https://analytics.google.com/analytics/web/' : null,
            'metrikaUrl' => $tracking['metrika'] !== '' ? 'https://metrika.yandex.ru/dashboard?id='.rawurlencode($tracking['metrika']) : null,
        ])->title(__('analytics.traffic.title'));
    }
}
