<?php

namespace App\Livewire\Admin\Settings;

use App\Actions\Settings\GenerateIndexNowKey;
use App\Actions\Settings\SaveIntegrationSettings;
use App\Enums\IntegrationSource;
use App\Enums\PaymentMode;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\ManagesIntegrationSettings;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Support\Admin\PasswordConfirmation;
use App\Support\CompanySettings;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use App\Support\Site\IndexNow;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings → Integrations (/admin/settings/integrations, plan 04b T7).
 * Sections Payme · Click · UzQR (each with a test / prod mode, plan 11 v2
 * §1.8) · Monitoring · IndexNow, plus the settings audit history (all areas,
 * latest 50). Telegram lives at /admin/telegram/settings: its keys and test
 * targets are a 404 here. Security rules: ManagesIntegrationSettings.
 */
#[Layout('layouts::admin')]
class Integrations extends Component
{
    use HandlesActionErrors, ManagesIntegrationSettings, WithAdminPagination;

    /**
     * Registry groups hidden from this screen by owner decision (plan 23):
     * Payme and Click. Their keys stay in the registry and the database, and
     * every save / secret / test action answers 404 for them.
     */
    public const HIDDEN_GROUPS = ['payme', 'click'];

    /**
     * Payment providers shown here with a test / prod mode (plan 11 v2 §1.8).
     */
    public const PAYMENT_GROUPS = ['uzqr'];

    /**
     * Set by the server when save('uzqr') opened the "switch to
     * live" dialog for that provider; confirmProd() only runs while it is
     * set. A UX guard against an accidental switch, not a security boundary
     * (the admin may save "prod" anyway); #[Locked] so the browser can't set it.
     */
    #[Locked]
    public ?string $prodConfirmGroup = null;

    /**
     * The sections of this screen: every registry group except Telegram,
     * which has its own page (Telegram\Settings, plan 21 stream C).
     *
     * @return list<string>
     */
    protected function integrationGroups(): array
    {
        return array_values(array_diff(IntegrationRegistry::GROUPS, ['telegram'], self::HIDDEN_GROUPS));
    }

    /**
     * @return list<string>
     */
    protected function allowedTestTargets(): array
    {
        return ['uzqr', 'indexnow'];
    }

    protected function returnRouteName(): string
    {
        return 'admin.settings.integrations';
    }

    /**
     * Switching a payment provider to "prod" (or UzQR with open invoices)
     * first opens a confirm dialog; see $prodConfirmGroup.
     *
     * @param  array<string, mixed>  $values
     */
    protected function interceptSave(string $group, array $values, SaveIntegrationSettings $saveSettings): bool
    {
        if (! in_array($group, self::PAYMENT_GROUPS, true) || (! $this->switchesToProd($group, $values) && ! $this->switchesUzqrWithOpenInvoices($group, $values))) {
            return false;
        }

        // Validate first, so the dialog never opens for a value that can't be saved.
        $this->runFormAction(fn (): array => $saveSettings->validate($values), 'form.values.');

        $this->prodConfirmGroup = $group;

        $this->dispatch('confirm',
            title: __("admin.settings.integrations.{$group}.prod_confirm_title"),
            body: ($this->switchesToProd($group, $values) ? __("admin.settings.integrations.{$group}.prod_confirm_body") : '')
                .($this->switchesUzqrWithOpenInvoices($group, $values) ? ' '.__('admin.settings.integrations.uzqr.open_invoices_confirm', ['count' => app(UzqrConfig::class)->openCount()]) : '')
                .(app()->isProduction() ? '' : ' '.__('admin.settings.integrations.payme.not_production')),
            label: __('admin.settings.integrations.payme.prod_confirm_label'),
            componentId: $this->getId(),
            method: 'confirmProd',
        );

        return true;
    }

    /**
     * Called by the confirm dialog after save('uzqr') asked for it.
     */
    public function confirmProd(SaveIntegrationSettings $saveSettings): void
    {
        $this->authorize('manageIntegrations', Setting::class);

        $group = $this->prodConfirmGroup;

        abort_unless(in_array($group, self::PAYMENT_GROUPS, true), 403);

        $this->prodConfirmGroup = null;

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        $this->persist($group, $this->form->valuesFor($group), $saveSettings);
    }

    /**
     * New IndexNow key (called from the confirm dialog). The old key URL
     * stops answering at once; the new value is never shown.
     */
    public function generateIndexNowKey(GenerateIndexNowKey $generate): void
    {
        $this->resetSecretInputs();

        $this->authorize('manageIntegrations', Setting::class);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        if (Secrets::source(GenerateIndexNowKey::KEY) === IntegrationSource::Env) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.settings.integrations.locked_hint', ['env' => IntegrationRegistry::field(GenerateIndexNowKey::KEY)->env]), type: 'error');

            return;
        }

        $generate->handle(auth()->user(), request()->ip());

        $this->forgetTestResults('indexnow');

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('admin.settings.integrations.indexnow.generated'), type: 'success');
    }

    public function render(): View
    {
        return view('livewire.admin.settings.integrations', [
            'sections' => $this->fieldStates(),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'prodGroups' => array_values(array_filter(self::PAYMENT_GROUPS, fn (string $group): bool => Secrets::get("{$group}.mode") === PaymentMode::Prod->value || data_get($this->form->values, "{$group}.mode") === PaymentMode::Prod->value)),
            'testKeyMissing' => array_values(array_filter(self::PAYMENT_GROUPS, fn (string $group): bool => (data_get($this->form->values, "{$group}.mode") ?: Secrets::get("{$group}.mode")) === PaymentMode::Test->value && ! Secrets::isConfigured(match ($group) {
                'payme' => 'payme.test_key',
                'click' => 'click.test_secret_key',
                default => 'uzqr.test_api_key',
            }))),
            'uzqr' => $this->uzqrState(),
            'indexnow' => $this->indexNowState(),
            'webhooksPublic' => IntegrationRegistry::webhooksArePublic(),
            'isProduction' => app()->isProduction(),
            'webhookUrls' => IntegrationRegistry::webhookUrls(),
            'audits' => $this->audits(),
        ])->title(__('admin.settings.integrations.title'));
    }

    /**
     * The latest AUDIT_LIMIT changes of every settings area except the staff
     * inbox (T21-35), AUDIT_PER_PAGE per page.
     *
     * @return LengthAwarePaginator<int, SettingsAudit>
     */
    protected function audits(): LengthAwarePaginator
    {
        return $this->paginateAudits(SettingsAudit::query()->where('area', '!=', SettingsArea::TelegramInbox->value));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function switchesToProd(string $group, array $values): bool
    {
        return ($values["{$group}.mode"] ?? null) === PaymentMode::Prod->value
            && Secrets::get("{$group}.mode") !== PaymentMode::Prod->value;
    }

    /**
     * Changing the UzQR mode while invoices are open needs a confirmation:
     * they keep being polled with their own mode (condition 36).
     *
     * @param  array<string, mixed>  $values
     */
    protected function switchesUzqrWithOpenInvoices(string $group, array $values): bool
    {
        return $group === 'uzqr'
            && isset($values['uzqr.mode'])
            && $values['uzqr.mode'] !== (Secrets::get('uzqr.mode') ?? PaymentMode::Test->value)
            && app(UzqrConfig::class)->openCount() > 0;
    }

    /**
     * IndexNow panel facts (display-safe: counts, the masked key URL, the
     * result log without URLs).
     *
     * @return array{active: bool, production: bool, public: bool, open: bool, key_url: string|null, log: list<array{at: string, urls: int, status: string, code: string}>, pending: int, backlog: int}
     */
    protected function indexNowState(): array
    {
        $hint = Secrets::hint('indexnow.key');

        return [
            'active' => IndexNow::isActive(),
            'production' => app()->isProduction(),
            'public' => IntegrationRegistry::webhooksArePublic(),
            'open' => app(CompanySettings::class)->isIndexingOpen(),
            'key_url' => $hint === null ? null : rtrim((string) config('app.url'), '/').'/••••'.$hint.'.txt',
            'log' => IndexNow::entries(),
            'pending' => IndexNow::pendingCount(),
            'backlog' => count(IndexNow::backlog()),
        ];
    }

    /**
     * UzQR panel facts (display-safe: hosts only, no keys).
     *
     * @return array{active_mode: PaymentMode, test_available: bool, go_live: bool, missing: list<string>, prod_host: string|null, test_host: string|null, auth_failed: bool, open: int}
     */
    protected function uzqrState(): array
    {
        $config = app(UzqrConfig::class);

        return [
            'active_mode' => $config->activeMode(),
            'test_available' => $config->testAvailable(),
            'go_live' => $config->goLiveOpen(),
            'missing' => $config->missingBankAnswers(),
            'prod_host' => $config->host(PaymentMode::Prod),
            'test_host' => $config->host(PaymentMode::Test),
            'auth_failed' => $config->authFailed($config->activeMode()),
            'open' => $config->openCount(),
        ];
    }
}
