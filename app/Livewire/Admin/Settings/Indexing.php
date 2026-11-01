<?php

namespace App\Livewire\Admin\Settings;

use App\Actions\Settings\UpdateSearchIndexing;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Admin\PasswordConfirmation;
use App\Support\CompanySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings → Indexing (/admin/settings/indexing, plan 15 C): whether search
 * engines and AI crawlers may index the site. Closed (the default) adds
 * noindex, nofollow everywhere, closes robots.txt and hides sitemap.xml and
 * llms.txt; people still see the site.
 *
 * Admin only (manageArea Indexing = settings.company + settings.integrations),
 * authorized on every request (boot) and before saving; saving needs a
 * password confirmation from the last 15 minutes and goes through
 * UpdateSearchIndexing (audited, caches flushed).
 */
#[Layout('layouts::admin')]
class Indexing extends Component
{
    /**
     * Number of recent changes shown under the switch.
     */
    public const AUDIT_LIMIT = 10;

    public bool $open = false;

    public function boot(): void
    {
        $this->authorize('manageArea', [Setting::class, SettingsArea::Indexing]);
    }

    public function mount(CompanySettings $settings): void
    {
        $this->open = $settings->isIndexingOpen();
    }

    public function save(UpdateSearchIndexing $updateIndexing, CompanySettings $settings): void
    {
        $this->authorize('manageArea', [Setting::class, SettingsArea::Indexing]);

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.settings.indexing')), navigate: false);

            return;
        }

        $changed = $updateIndexing->handle($this->open, auth()->user(), request()->ip());

        CompanySettings::flush();
        $this->open = $settings->isIndexingOpen();

        $this->dispatch('toast', title: $changed ? __('admin.toast.saved') : __('admin.settings.company.no_changes'), type: $changed ? 'success' : 'info');
    }

    /**
     * Recent switch changes (who / when / opened or closed).
     *
     * @return Collection<int, SettingsAudit>
     */
    protected function audits(): Collection
    {
        return SettingsAudit::query()
            ->where('area', SettingsArea::Indexing->value)
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::AUDIT_LIMIT)
            ->get();
    }

    public function render(CompanySettings $settings): View
    {
        return view('livewire.admin.settings.indexing', [
            'isOpen' => $settings->isIndexingOpen(),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'audits' => $this->audits(),
        ])->title(__('admin.settings.indexing.title'));
    }
}
