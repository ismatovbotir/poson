<?php

namespace App\Livewire\Admin\Careers;

use App\Actions\Careers\SaveCareersSettings;
use App\Models\Setting;
use App\Support\Careers\CareersCv;
use App\Support\Careers\CareersSwitch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Careers → Application form (/admin/careers/settings, plan 31 CR-3 / CR-11):
 * the three kill switches of the application form, Admin only
 * (SettingPolicy::manageCareers), authorized on mount, every request
 * (hydrate) and before saving. Saved by SaveCareersSettings (audited without
 * values). The site-wide caps and whether the management chat is usable are
 * shown read-only: they come from config / Settings → Telegram.
 */
#[Layout('layouts::admin')]
class Settings extends Component
{
    public bool $applications_enabled = true;

    public bool $open_applications_enabled = true;

    public bool $cv_enabled = true;

    public function mount(): void
    {
        $this->authorize('manageCareers', Setting::class);

        $this->load();
    }

    public function hydrate(): void
    {
        $this->authorize('manageCareers', Setting::class);
    }

    public function save(SaveCareersSettings $saveSettings): void
    {
        $this->authorize('manageCareers', Setting::class);

        $this->validate([
            'applications_enabled' => ['boolean'],
            'open_applications_enabled' => ['boolean'],
            'cv_enabled' => ['boolean'],
        ]);

        $changed = $saveSettings->handle([
            'applications_enabled' => $this->applications_enabled,
            'open_applications_enabled' => $this->open_applications_enabled,
            'cv_enabled' => $this->cv_enabled,
        ], auth()->user(), request()->ip());

        $this->load();

        $this->dispatch('toast', title: $changed > 0 ? __('admin.toast.saved') : __('admin.settings.company.no_changes'), type: $changed > 0 ? 'success' : 'info');
    }

    protected function load(): void
    {
        CareersSwitch::forget();

        $this->applications_enabled = CareersSwitch::applicationsEnabled();
        $this->open_applications_enabled = CareersSwitch::openApplicationsEnabled() || $this->storedOpenEnabled();
        $this->cv_enabled = CareersSwitch::cvEnabled();
    }

    /**
     * The open-application switch as stored (CareersSwitch ANDs it with the
     * main switch, so the form must not show it off just because that is off).
     */
    private function storedOpenEnabled(): bool
    {
        $stored = Setting::query()->where('key', (string) config('careers.settings_keys.open_applications_enabled'))->first()?->value;

        return is_bool($stored) ? $stored : (bool) config('careers.open_applications_enabled', true);
    }

    public function render(): View
    {
        $this->authorize('manageCareers', Setting::class);

        return view('livewire.admin.careers.settings', [
            'caps' => (array) config('careers.caps'),
            'chatConfigured' => CareersCv::isConfigured(),
        ])->title(__('careers.admin.settings.title'));
    }
}
