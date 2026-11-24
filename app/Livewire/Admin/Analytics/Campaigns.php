<?php

namespace App\Livewire\Admin\Analytics;

use App\Actions\Analytics\ExportOfflineConversions;
use App\Actions\Settings\UpdateAdClickIds;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Concerns\WithAnalyticsRange;
use App\Models\SettingsAudit;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Analytics\CampaignReport;
use App\Support\Analytics\TrafficReport;
use App\Support\CompanySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Analytics → Campaigns (/admin/analytics/campaigns, plan 40 §4, YD-13),
 * Admin only through analytics.view (WithAnalyticsRange, every request).
 * Leads, orders and accepted quotes per UTM campaign (CampaignReport),
 * optionally Direct only. No export of this table.
 *
 * Admins with analytics.export also get (plan 40 §3, §11, YD-8, YD-10):
 *  - the ad click id switch (UpdateAdClickIds; recent password, audited);
 *  - the offline-conversion CSV form, a plain POST with CSRF to
 *    admin.analytics.offline-conversions (disabled while the switch is off).
 */
#[Layout('layouts::admin')]
class Campaigns extends Component
{
    use WithAnalyticsRange;

    public const AUDIT_LIMIT = 10;

    #[Url]
    public bool $direct = false;

    /**
     * Untyped on purpose: a tampered value ("abc", an array) is a validation error, not a TypeError.
     */
    public mixed $clickIdsEnabled = false;

    public function mount(CompanySettings $settings): void
    {
        $this->clickIdsEnabled = $settings->adClickIdsEnabled();
    }

    public function saveClickIds(UpdateAdClickIds $update, CompanySettings $settings): void
    {
        $this->authorize('analytics.export');

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.analytics.campaigns')), navigate: false);

            return;
        }

        $this->validate(
            ['clickIdsEnabled' => ['required', 'boolean']],
            [],
            ['clickIdsEnabled' => __('analytics.campaigns.click_ids.label')],
        );

        $changed = $update->handle((bool) $this->clickIdsEnabled, auth()->user(), request()->ip());

        CompanySettings::flush();
        $this->clickIdsEnabled = app(CompanySettings::class)->adClickIdsEnabled();

        $this->dispatch('toast', title: $changed ? __('admin.toast.saved') : __('admin.settings.company.no_changes'), type: $changed ? 'success' : 'info');
    }

    /**
     * @return Collection<int, SettingsAudit>
     */
    protected function audits(): Collection
    {
        return SettingsAudit::query()
            ->where('area', SettingsArea::Analytics->value)
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::AUDIT_LIMIT)
            ->get();
    }

    public function render(CompanySettings $settings): View
    {
        $report = (new CampaignReport($this->days, $this->direct))->build();
        $canExport = auth()->user()?->can('analytics.export') === true;
        $today = ExportOfflineConversions::today();

        return view('livewire.admin.analytics.campaigns', [
            'ranges' => TrafficReport::RANGES,
            'rows' => $report['rows'],
            'totals' => $report['totals'],
            'canExport' => $canExport,
            'switchOn' => $settings->adClickIdsEnabled(),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'audits' => $canExport ? $this->audits() : null,
            'export' => $canExport ? [
                'from' => ExportOfflineConversions::defaultFrom(),
                'to' => $today->toDateString(),
                'min' => $today->subDays(ExportOfflineConversions::maxDaysBack())->toDateString(),
                'max' => $today->toDateString(),
                'last' => ExportOfflineConversions::lastExportUntil(),
                'goals' => ExportOfflineConversions::allowedGoals(),
                'days' => ExportOfflineConversions::maxDaysBack(),
            ] : null,
        ])->title(__('analytics.campaigns.title'));
    }
}
