<?php

namespace App\Livewire\Admin\Concerns;

use App\Actions\Settings\ClearSecret;
use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Actions\Settings\TestIntegrationConnection;
use App\Enums\IntegrationSource;
use App\Livewire\Forms\Admin\Settings\IntegrationSettingsForm;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Integrations\IntegrationField;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * A settings screen for a set of IntegrationRegistry groups. The component
 * supplies integrationGroups(), allowedTestTargets() and returnRouteName(),
 * and uses HandlesActionErrors and WithAdminPagination.
 *
 * Security (plan 04 S5, S7; plan 21 T21-1 to T21-3):
 * - settings.integrations (Admin only), authorized in boot() and again in
 *   each action; a group, key or target of another screen is a 404 first;
 * - every save, clear and test needs a password confirmation from the last
 *   15 minutes, else the user is sent to /admin/confirm-password;
 * - secrets are write-only: typed into $secretInputs, which is reset after
 *   every attempt and in dehydrate() before every response, so a typed or
 *   saved value is never held in a public property, the snapshot or the HTML.
 *   Only the status (.env / ••••1234 / not set) is rendered.
 *
 * Public methods are callable from the browser: every helper here is protected.
 */
trait ManagesIntegrationSettings
{
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
     * Last connection test per test id: {ok, message} (safe text only). Set by
     * the server only; forgotten when a value of the group changes.
     *
     * @var array<string, array{ok: bool, message: string}>
     */
    #[Locked]
    public array $testResults = [];

    /**
     * The registry groups this screen manages.
     *
     * @return list<string>
     */
    abstract protected function integrationGroups(): array;

    /**
     * The connection test targets this screen may run.
     *
     * @return list<string>
     */
    abstract protected function allowedTestTargets(): array;

    /**
     * Name of the route the password confirmation returns to (server-defined).
     */
    abstract protected function returnRouteName(): string;

    /**
     * Runs on every request (initial render and each update).
     */
    public function boot(): void
    {
        $this->authorize('manageIntegrations', Setting::class);
    }

    public function mount(): void
    {
        $this->form->fillFrom($this->integrationGroups());
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
     * Save the non-secret values of one section.
     */
    public function save(string $group, SaveIntegrationSettings $saveSettings): void
    {
        abort_unless(in_array($group, $this->integrationGroups(), true), 404);

        $this->authorize('manageIntegrations', Setting::class);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        $values = $this->form->valuesFor($group);

        if ($this->interceptSave($group, $values, $saveSettings)) {
            return;
        }

        $this->persist($group, $values, $saveSettings);
    }

    /**
     * Store the typed value of one secret.
     */
    public function replaceSecret(string $key, SetSecret $setSecret): void
    {
        try {
            $field = $this->secretField($key);

            // Generated values (webhook secret, IndexNow key) are never typed in.
            abort_if($field->generated, 404);

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

        $this->forgetTestResults($field->group);

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

        // The .env value stays effective: deleting the stored row would change nothing visible and leave a false "cleared" audit row.
        if (Secrets::source($key) === IntegrationSource::Env) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.settings.integrations.locked_hint', ['env' => $field->env]), type: 'error');

            return;
        }

        $cleared = $clearSecret->handle($key, auth()->user(), request()->ip());

        $this->forgetTestResults($field->group);

        $this->dispatch('toast', title: $cleared ? __('admin.toast.done') : __('admin.settings.integrations.no_changes'), body: $cleared ? __('admin.settings.integrations.secret_cleared', ['name' => $field->label()]) : null, type: $cleared ? 'success' : 'info');
    }

    /**
     * Test the saved (effective) value: telegram, telegram_chat + chat.
     */
    public function test(string $target, TestIntegrationConnection $tester, ?string $chat = null): void
    {
        $this->resetSecretInputs();

        abort_unless(in_array($target, $this->allowedTestTargets(), true), 404);
        abort_unless($target !== 'telegram_chat' || in_array($chat, TestIntegrationConnection::CHATS, true), 404);

        $this->authorize('manageIntegrations', Setting::class);

        if (! $this->ensurePasswordConfirmed()) {
            return;
        }

        $result = $tester->handle(auth()->user(), $target, $chat);

        $this->testResults[static::testId($target, $chat)] = $result->toArray();
    }

    /**
     * Send the user to the password confirmation and back to this screen.
     */
    public function confirmPassword(): void
    {
        $this->authorize('manageIntegrations', Setting::class);

        $this->redirect(PasswordConfirmation::url(route($this->returnRouteName())), navigate: false);
    }

    /**
     * Last step of save(): true when the component took over (e.g. opened a dialog).
     *
     * @param  array<string, mixed>  $values
     */
    protected function interceptSave(string $group, array $values, SaveIntegrationSettings $saveSettings): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function persist(string $group, array $values, SaveIntegrationSettings $saveSettings): void
    {
        $changed = $this->runFormAction(fn (): array => $saveSettings->handle($values, auth()->user(), request()->ip()), 'form.values.');

        $this->form->fillFrom($this->integrationGroups());

        if ($changed === []) {
            $this->dispatch('toast', title: __('admin.settings.integrations.no_changes'), type: 'info');

            return;
        }

        $this->forgetTestResults($group);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.settings.integrations.saved', ['section' => __('admin.settings.integrations.groups.'.$group)]), type: 'success');
    }

    /**
     * A changed value makes every earlier result of its group stale: a chat
     * test depends on the token too, so no old "passed" may stay on screen.
     * Test ids start with the group name (see testId()).
     */
    protected function forgetTestResults(string $group): void
    {
        $this->testResults = array_filter(
            $this->testResults,
            fn (string $testId): bool => ! str_starts_with($testId, $group.'_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Key of $testResults for a test.
     */
    protected static function testId(string $target, ?string $chat = null): string
    {
        return match ($target) {
            'telegram' => 'telegram_bot_token',
            'telegram_chat' => 'telegram_chats_'.$chat,
            'uzqr' => 'uzqr_connection',
            'indexnow' => 'indexnow_key',
            default => $target,
        };
    }

    /**
     * Status of every field of this screen, group => key => state.
     *
     * @return array<string, array<string, array{field: IntegrationField, source: IntegrationSource, locked: bool, configured: bool, hint: string|null, unreadable: bool}>>
     */
    protected function fieldStates(): array
    {
        $sections = [];

        foreach ($this->integrationGroups() as $group) {
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

        return $sections;
    }

    /**
     * The latest AUDIT_LIMIT rows of a query, AUDIT_PER_PAGE per page.
     *
     * @param  Builder<SettingsAudit>  $query
     * @return LengthAwarePaginator<int, SettingsAudit>
     */
    protected function paginateAudits(Builder $query): LengthAwarePaginator
    {
        $rows = $query->with('user:id,name,email')->latest('id')->limit(self::AUDIT_LIMIT)->get();
        $page = max(1, min((int) $this->getPage('auditPage'), max(1, (int) ceil($rows->count() / self::AUDIT_PER_PAGE))));

        return new LengthAwarePaginator($rows->forPage($page, self::AUDIT_PER_PAGE)->values(), $rows->count(), self::AUDIT_PER_PAGE, $page, ['pageName' => 'auditPage']);
    }

    protected function secretField(string $key): IntegrationField
    {
        abort_unless(IntegrationRegistry::has($key) && IntegrationRegistry::field($key)->secret && in_array(IntegrationRegistry::field($key)->group, $this->integrationGroups(), true), 404);

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

        foreach (IntegrationRegistry::secrets() as $key => $field) {
            if (in_array($field->group, $this->integrationGroups(), true)) {
                data_set($inputs, $key, '');
            }
        }

        $this->secretInputs = $inputs;
    }
}
