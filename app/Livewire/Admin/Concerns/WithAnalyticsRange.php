<?php

namespace App\Livewire\Admin\Concerns;

use App\Support\Analytics\TrafficReport;
use Livewire\Attributes\Url;

/**
 * Shared by the analytics screens: analytics.view on every request and the
 * 7 / 30 / 90-day range in the URL. Today's figures come from the
 * aggregates the 15-minute schedule rebuilds (screens never aggregate).
 */
trait WithAnalyticsRange
{
    #[Url]
    public int $days = 30;

    /**
     * Runs on every request (initial render and each update).
     */
    public function bootWithAnalyticsRange(): void
    {
        $this->authorize('analytics.view');
    }

    public function mountWithAnalyticsRange(): void
    {
        $this->normalizeDays();
    }

    public function updatedDays(): void
    {
        $this->normalizeDays();
    }

    public function setDays(int $days): void
    {
        $this->days = $days;
        $this->normalizeDays();
    }

    protected function report(): TrafficReport
    {
        return new TrafficReport($this->days);
    }

    protected function normalizeDays(): void
    {
        if (! in_array($this->days, TrafficReport::RANGES, true)) {
            $this->days = 30;
        }
    }
}
