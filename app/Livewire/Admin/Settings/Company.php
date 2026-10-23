<?php

namespace App\Livewire\Admin\Settings;

use App\Actions\Settings\UpdateCompanyMedia;
use App\Actions\Settings\UpdateCompanySettings;
use App\Enums\PhoneLabel;
use App\Enums\SettingsArea;
use App\Enums\SocialNetwork;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Settings\AiBotsForm;
use App\Livewire\Forms\Admin\Settings\ContactsForm;
use App\Livewire\Forms\Admin\Settings\HeroForm;
use App\Livewire\Forms\Admin\Settings\LegalForm;
use App\Livewire\Forms\Admin\Settings\SeoForm;
use App\Livewire\Forms\Admin\Settings\SettingsTabForm;
use App\Livewire\Forms\Admin\Settings\SocialForm;
use App\Livewire\Forms\Admin\Settings\TrackingForm;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Site\AiBotRules;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Settings → Company (/admin/settings/company, plan 04a T2). Tabs Contacts ·
 * Legal · Social · Hero · SEO · Tracking · AI bots, one Form object each.
 * "Save" saves the open tab; the other tabs keep their edits until saved.
 * The FAQ tab (plan 04 T12) holds the general FAQ editor (child component
 * Admin\Faq\FaqItems, which saves each item itself) and has no Save.
 *
 * Authorized on every request in boot() (settings.company), and per area on
 * save (Legal and Tracking also need settings.integrations). Writes go
 * through UpdateCompanySettings (one transaction, changed keys only,
 * audited without values) and then, after that commit, UpdateCompanyMedia
 * (files validated first, old images removed only once the new ones exist).
 */
#[Layout('layouts::admin')]
class Company extends Component
{
    use HandlesActionErrors, WithFileUploads;

    public const TABS = ['contacts', 'legal', 'social', 'hero', 'seo', 'tracking', 'ai_bots', 'faq'];

    /**
     * Tabs saved through a Form object and the Save button.
     */
    public const FORM_TABS = ['contacts', 'legal', 'social', 'hero', 'seo', 'tracking', 'ai_bots'];

    #[Url]
    public string $tab = 'contacts';

    public ContactsForm $contacts;

    public LegalForm $legal;

    public SocialForm $social;

    public HeroForm $hero;

    public SeoForm $seo;

    public TrackingForm $tracking;

    public AiBotsForm $aiBots;

    /**
     * Runs on every request (initial render and each update, uploads included).
     */
    public function boot(): void
    {
        $this->authorize('manageCompany', Setting::class);
    }

    public function mount(CompanySettings $settings): void
    {
        foreach (self::FORM_TABS as $tab) {
            if ($this->canManage(SettingsArea::from($tab))) {
                $this->form($tab)->fillFrom($settings);
            }
        }

        $this->ensureTabAllowed();
    }

    public function updatedTab(): void
    {
        $this->ensureTabAllowed();
    }

    public function addPhone(): void
    {
        $this->contacts->addPhone();
    }

    public function removePhone(int $index): void
    {
        $this->contacts->removePhone($index);
    }

    public function addOpeningHours(): void
    {
        $this->contacts->addOpeningHours();
    }

    public function removeOpeningHours(int $index): void
    {
        $this->contacts->removeOpeningHours($index);
    }

    public function addSocialLink(): void
    {
        $this->social->addLink();
    }

    public function removeSocialLink(int $index): void
    {
        $this->social->removeLink($index);
    }

    /**
     * Fill the AI bot toggles from a preset (saved with Save, like any edit).
     */
    public function applyBotPreset(string $preset): void
    {
        $this->authorize('manageArea', [Setting::class, SettingsArea::AiBots]);

        $this->aiBots->applyPreset($preset);
    }

    /**
     * Validate a new upload right away (type, size, dimensions), before saving.
     */
    public function updatedSeoLogo(): void
    {
        $this->validateOnly('seo.logo');
    }

    public function updatedSeoOgImage(): void
    {
        $this->validateOnly('seo.og_image');
    }

    public function removeLogo(): void
    {
        if ($this->seo->logo !== null) {
            $this->seo->logo = null;

            return;
        }

        $this->seo->remove_logo = true;
    }

    public function updatedLegalStamp(): void
    {
        $this->validateOnly('legal.stamp');
    }

    public function updatedLegalSignature(): void
    {
        $this->validateOnly('legal.signature');
    }

    /**
     * Clear a new stamp / signature upload, or mark the saved one for removal.
     */
    public function removeLegalImage(string $field): void
    {
        if (! in_array($field, [Setting::STAMP, Setting::SIGNATURE], true)) {
            return;
        }

        if ($this->legal->{$field} !== null) {
            $this->legal->{$field} = null;

            return;
        }

        $this->legal->{'remove_'.$field} = true;
    }

    public function removeOgImage(): void
    {
        if ($this->seo->og_image !== null) {
            $this->seo->og_image = null;

            return;
        }

        $this->seo->remove_og_image = true;
    }

    /**
     * Save the open tab.
     */
    public function save(UpdateCompanySettings $updateSettings, UpdateCompanyMedia $updateMedia, CompanySettings $settings): void
    {
        $area = SettingsArea::tryFrom($this->tab);

        abort_unless($area !== null && in_array($area->value, self::FORM_TABS, true), 404);

        $this->authorize('manageArea', [Setting::class, $area]);

        $form = $this->form($area->value);
        $form->normalize();
        $form->validate();

        $user = auth()->user();
        $ip = request()->ip();
        $mediaChanges = $form instanceof SeoForm || $form instanceof LegalForm ? $form->mediaChanges() : [];

        // Every file is checked before anything is written.
        $this->runFormAction(fn () => $updateMedia->validate($mediaChanges), "{$area->value}.");

        $changed = $updateSettings->handle($area, $form->values(), $user, $ip) !== [];

        if ($mediaChanges !== []) {
            $changed = $this->runFormAction(fn (): bool => $updateMedia->handle($mediaChanges, $user, $ip), "{$area->value}.") || $changed;
        }

        CompanySettings::flush();
        $form->fillFrom($settings);

        if ($changed) {
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.settings.company.saved', ['tab' => __("admin.settings.company.tabs.{$area->value}")]), type: 'success');

            return;
        }

        $this->dispatch('toast', title: __('admin.settings.company.no_changes'), type: 'info');
    }

    public function render(): View
    {
        $tabs = array_values(array_filter(self::TABS, fn (string $tab): bool => $this->canManage(SettingsArea::from($tab))));
        $media = $this->mediaState();

        return view('livewire.admin.settings.company', [
            'tabs' => $tabs,
            'phoneLabels' => collect(PhoneLabel::cases())->mapWithKeys(fn (PhoneLabel $label): array => [$label->value => $label->label()])->all(),
            'networks' => SocialNetwork::cases(),
            'mapPreviewUrl' => CompanySettings::mapUrlFor($this->contacts->lat, $this->contacts->lng),
            'logoUrl' => $media['logo']['url'],
            'ogImageUrl' => $media['og_image']['url'],
            'logoProcessing' => $media['logo']['processing'],
            'ogImageProcessing' => $media['og_image']['processing'],
            'aiBotList' => AiBotRules::editable(),
            'searchEngines' => AiBotRules::searchEngines(),
            'botPreset' => $this->tab === 'ai_bots' ? $this->aiBots->currentPreset() : null,
            'hasSave' => in_array($this->tab, self::FORM_TABS, true),
            'legalImages' => $this->legalImages(),
        ])->title(__('admin.settings.company.title'));
    }

    protected function form(string $tab): SettingsTabForm
    {
        return match ($tab) {
            'contacts' => $this->contacts,
            'legal' => $this->legal,
            'social' => $this->social,
            'hero' => $this->hero,
            'seo' => $this->seo,
            'tracking' => $this->tracking,
            'ai_bots' => $this->aiBots,
        };
    }

    /**
     * Previews of the saved stamp and signature, as data URIs (the files are
     * private and have no URL), only on the Legal tab; none when marked for removal.
     *
     * @return array<string, string|null>
     */
    protected function legalImages(): array
    {
        $images = [Setting::STAMP => null, Setting::SIGNATURE => null];

        if ($this->tab !== 'legal') {
            return $images;
        }

        foreach (array_keys($images) as $key) {
            $images[$key] = $this->legal->{'remove_'.$key} ? null : Setting::privateImageDataUri($key);
        }

        return $images;
    }

    protected function canManage(SettingsArea $area): bool
    {
        return (bool) auth()->user()?->can('manageArea', [Setting::class, $area]);
    }

    protected function ensureTabAllowed(): void
    {
        $area = SettingsArea::tryFrom($this->tab);

        if ($area === null || ! in_array($this->tab, self::TABS, true) || ! $this->canManage($area)) {
            $this->tab = 'contacts';
        }
    }

    /**
     * Preview URL and "processing" flag of the logo and OG image, with one
     * query, only on the SEO tab. A file marked for removal shows nothing.
     *
     * @return array<string, array{url: string|null, processing: bool}>
     */
    protected function mediaState(): array
    {
        $state = [
            Setting::LOGO => ['url' => null, 'processing' => false],
            Setting::OG_IMAGE => ['url' => null, 'processing' => false],
        ];

        if ($this->tab !== 'seo') {
            return $state;
        }

        $rows = Setting::query()->whereIn('key', array_keys(Setting::MEDIA))->with('media')->get()->keyBy('key');

        foreach ([Setting::LOGO => 'logo', Setting::OG_IMAGE => 'og_image'] as $key => $field) {
            $setting = $rows->get($key);

            if ($setting === null || $this->seo->{'remove_'.$field}) {
                continue;
            }

            $url = $setting->mediaUrl();
            $hasMedia = $setting->getMedia(Setting::MEDIA[$key]['collection'])->isNotEmpty();

            $state[$key] = [
                'url' => $url,
                'processing' => $hasMedia && $url === null && $this->seo->{$field} === null,
            ];
        }

        return $state;
    }
}
