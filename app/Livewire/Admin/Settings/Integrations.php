<?php

namespace App\Livewire\Admin\Settings;

use App\Actions\Settings\ClearSecret;
use App\Actions\Settings\GenerateWebhookSecret;
use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Actions\Settings\TestIntegrationConnection;
use App\Enums\IntegrationSource;
use App\Enums\PaymeMode;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Livewire\Forms\Admin\Settings\IntegrationSettingsForm;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Integrations\IntegrationField;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings → Integrations (/admin/settings/integrations, plan 04b T7).
 * Sections AI · Telegram · Payme · Click · remove.bg, plus the settings
 * audit history (all areas, latest 50).
 *
 * Security (plan 04 S5, S7):
 * - settings.integrations (Admin only), authorized in boot() — so on every
 *   request — and again before each write;
 * - every save, clear, generate and test needs a password confirmation from
 *   the last 15 minutes, else the user is sent to /admin/confirm-password;
 * - secrets are write-only: typed into $secretInputs, which is reset after
 *   every attempt and in dehydrate() before every response, so a typed or
 *   saved value is never held in a public property, the snapshot or the HTML. Only the status
 *   (.env / ••••1234 / not set) is rendered.
 */
#[Layout('layouts::admin')]
class Integrations extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    public const AUDIT_LIMIT = 50;

    public const AUDIT_PER_PAGE = 10;

    public IntegrationSettingsForm $form;

    /**
     * Write-only secret inputs, nested by registry key; always reset after use.
     *
     * @var array<string, mixed>
     */
    public array $secretInputs = [];

    /**
     * Last connection test per test id: {ok, message} (safe text only).
     *
     * @var array<string, array{ok: bool, message: string}>
     */
    public array $testResults = [];

    /**
     * Set by the server when save('payme') opened the "switch to live"
     * dialog; confirmPaymeProd() only runs while it is set. A UX guard
     * against an accidental switch, not a security boundary (the admin
     * may save "prod" anyway); #[Locked] so the browser can't set it.
     */
    #[Locked]
    public bool $paymeProdConfirmPending = false;

    /**
     * Runs on every request (initial render and each update).
     */
    public function boot(): void
    {
        $this->authorize('manageIntegrations', Setting::class);
    }

    public function mount(): void
    {
        $this->form->fillFrom();
        $this->resetSecretInputs();
    }

    /**
     * Before every response: typed secrets never go back to the browser,
     * whatever the request was (another field, a test, an error).
     */
    public function dehydrate(): void
    {
        $this->resetSecretInputs();
    }

    /**
     * Save the non-secret values of one section. Switching Payme to "prod"
     * first opens a confirm dialog (see $paymeProdConfirmPending).
     */
    public function save(string $group, SaveIntegrationSettings $saveSettings): void
    {
        abort_unless(in_array($group, IntegrationRegistry::GROUPS, true), 404);

        $this->authorize('manageIntegrations', Setting::class);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        $values = $this->form->valuesFor($group);

        if ($group === 'payme' && $this->switchesPaymeToProd($values)) {
            // Validate first, so the dialog never opens for a value that can't be saved.
            $this->runFormAction(fn (): array => $saveSettings->validate($values), 'form.values.');

            $this->paymeProdConfirmPending = true;

            $this->dispatch('confirm',
                title: __('admin.settings.integrations.payme.prod_confirm_title'),
                body: __('admin.settings.integrations.payme.prod_confirm_body').(app()->isProduction() ? '' : ' '.__('admin.settings.integrations.payme.not_production')),
                label: __('admin.settings.integrations.payme.prod_confirm_label'),
                componentId: $this->getId(),
                method: 'confirmPaymeProd',
            );

            return;
        }

        $this->persist($group, $values, $saveSettings);
    }

    /**
     * Called by the confirm dialog after save('payme') asked for it.
     */
    public function confirmPaymeProd(SaveIntegrationSettings $saveSettings): void
    {
        $this->authorize('manageIntegrations', Setting::class);

        abort_unless($this->paymeProdConfirmPending, 403);

        $this->paymeProdConfirmPending = false;

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        $this->persist('payme', $this->form->valuesFor('payme'), $saveSettings);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function persist(string $group, array $values, SaveIntegrationSettings $saveSettings): void
    {
        $changed = $this->runFormAction(fn (): array => $saveSettings->handle($values, auth()->user(), request()->ip()), 'form.values.');

        $this->form->fillFrom();

        if ($changed === []) {
            $this->dispatch('toast', title: __('admin.settings.integrations.no_changes'), type: 'info');

            return;
        }

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.settings.integrations.saved', ['section' => __('admin.settings.integrations.groups.'.$group)]), type: 'success');
    }

    /**
     * Store the typed value of one secret.
     */
    public function replaceSecret(string $key, SetSecret $setSecret): void
    {
        try {
            $field = $this->secretField($key);

            $this->authorize('manageIntegrations', Setting::class);

            if (! $this->ensurePasswordConfirmed()) {
                return;
            }

            if (Secrets::source($key) === IntegrationSource::Env) {
                $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.settings.integrations.locked_hint', ['env' => $field->env]), type: 'error');

                return;
            }

            $value = data_get($this->secretInputs, $key);

            try {
                $setSecret->handle($key, is_string($value) ? $value : '', auth()->user(), request()->ip());
            } catch (ValidationException $exception) {
                // Shown under the input; the messages never contain the value.
                throw ValidationException::withMessages(['secretInputs.'.$key => $exception->errors()['value'] ?? [__('admin.toast.error')]]);
            }
        } finally {
            $this->resetSecretInputs();
        }

        unset($this->testResults[$field->langKey()]);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.settings.integrations.secret_saved', ['name' => $field->label()]), type: 'success');
    }

    /**
     * Delete the admin value of one secret (called from the confirm dialog).
     */
    public function clearSecret(string $key, ClearSecret $clearSecret): void
    {
        $this->resetSecretInputs();
        $field = $this->secretField($key);

        $this->authorize('manageIntegrations', Setting::class);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        $cleared = $clearSecret->handle($key, auth()->user(), request()->ip());

        unset($this->testResults[$field->langKey()]);

        $this->dispatch('toast', title: $cleared ? __('admin.toast.done') : __('admin.settings.integrations.no_changes'), body: $cleared ? __('admin.settings.integrations.secret_cleared', ['name' => $field->label()]) : null, type: $cleared ? 'success' : 'info');
    }

    /**
     * New Telegram webhook secret (called from the confirm dialog).
     */
    public function generateWebhookSecret(GenerateWebhookSecret $generate): void
    {
        $this->resetSecretInputs();

        $this->authorize('manageIntegrations', Setting::class);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        if (Secrets::source(GenerateWebhookSecret::KEY) === IntegrationSource::Env) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.settings.integrations.locked_hint', ['env' => IntegrationRegistry::field(GenerateWebhookSecret::KEY)->env]), type: 'error');

            return;
        }

        $generate->handle(auth()->user(), request()->ip());

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('admin.settings.integrations.telegram.webhook_generated'), type: 'success');
    }

    /**
     * Test the saved (effective) value: telegram, telegram_chat + chat,
     * anthropic, openai, removebg.
     */
    public function test(string $target, TestIntegrationConnection $tester, ?string $chat = null): void
    {
        $this->resetSecretInputs();

        abort_unless(in_array($target, TestIntegrationConnection::TARGETS, true), 404);
        abort_unless($target !== 'telegram_chat' || in_array($chat, TestIntegrationConnection::CHATS, true), 404);

        $this->authorize('manageIntegrations', Setting::class);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        $result = $tester->handle(auth()->user(), $target, $chat);

        $this->testResults[static::testId($target, $chat)] = $result->toArray();
    }

    /**
     * Send the user to the password confirmation and back here.
     */
    public function confirmPassword(): void
    {
        $this->redirect(PasswordConfirmation::url(route('admin.settings.integrations')), navigate: false);
    }

    /**
     * Key of $testResults for a test.
     */
    public static function testId(string $target, ?string $chat = null): string
    {
        return match ($target) {
            'telegram' => 'telegram_bot_token',
            'telegram_chat' => 'telegram_chats_'.$chat,
            'anthropic' => 'anthropic_api_key',
            'openai' => 'openai_api_key',
            'removebg' => 'removebg_api_key',
            default => $target,
        };
    }

    public function render(): View
    {
        $sections = [];

        foreach (IntegrationRegistry::GROUPS as $group) {
            foreach (IntegrationRegistry::group($group) as $key => $field) {
                $source = Secrets::source($key);

                $sections[$group][$key] = [
                    'field' => $field,
                    'source' => $source,
                    'locked' => $source === IntegrationSource::Env,
                    'configured' => Secrets::isConfigured($key),
                    'hint' => $field->secret ? Secrets::hint($key) : null,
                    'unreadable' => $field->secret && Secrets::isUnreadable($key),
                ];
            }
        }

        return view('livewire.admin.settings.integrations', [
            'sections' => $sections,
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'paymeProd' => Secrets::get('payme.mode') === PaymeMode::Prod->value || data_get($this->form->values, 'payme.mode') === PaymeMode::Prod->value,
            'paymeTestKeyMissing' => (data_get($this->form->values, 'payme.mode') ?: Secrets::get('payme.mode')) === PaymeMode::Test->value && ! Secrets::isConfigured('payme.test_key'),
            'webhooksPublic' => IntegrationRegistry::webhooksArePublic(),
            'isProduction' => app()->isProduction(),
            'webhookUrls' => IntegrationRegistry::webhookUrls(),
            'audits' => $this->audits(),
        ])->title(__('admin.settings.integrations.title'));
    }

    /**
     * The latest AUDIT_LIMIT changes of every settings area, AUDIT_PER_PAGE per page.
     *
     * @return LengthAwarePaginator<int, SettingsAudit>
     */
    protected function audits(): LengthAwarePaginator
    {
        $rows = SettingsAudit::query()->with('user:id,name,email')->latest('id')->limit(self::AUDIT_LIMIT)->get();
        $page = max(1, min((int) $this->getPage('auditPage'), max(1, (int) ceil($rows->count() / self::AUDIT_PER_PAGE))));

        return new LengthAwarePaginator($rows->forPage($page, self::AUDIT_PER_PAGE)->values(), $rows->count(), self::AUDIT_PER_PAGE, $page, ['pageName' => 'auditPage']);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function switchesPaymeToProd(array $values): bool
    {
        return ($values['payme.mode'] ?? null) === PaymeMode::Prod->value
            && Secrets::get('payme.mode') !== PaymeMode::Prod->value;
    }

    protected function secretField(string $key): IntegrationField
    {
        abort_unless(IntegrationRegistry::has($key) && IntegrationRegistry::field($key)->secret, 404);

        return IntegrationRegistry::field($key);
    }

    /**
     * True when the password was confirmed recently; otherwise redirects to
     * the confirmation page (and back here afterwards).
     */
    protected function ensurePasswordConfirmed(): bool
    {
        if (PasswordConfirmation::isRecent()) {
            return true;
        }

        $this->confirmPassword();

        return false;
    }

    protected function resetSecretInputs(): void
    {
        $inputs = [];

        foreach (array_keys(IntegrationRegistry::secrets()) as $key) {
            data_set($inputs, $key, '');
        }

        $this->secretInputs = $inputs;
    }
}
