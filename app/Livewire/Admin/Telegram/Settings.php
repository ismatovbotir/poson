<?php

namespace App\Livewire\Admin\Telegram;

use App\Actions\Settings\GenerateWebhookSecret;
use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Enums\IntegrationSource;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\ManagesIntegrationSettings;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Telegram → Settings (/admin/telegram/settings, plan 21 stream C): bot token,
 * webhook secret, chat IDs, alert switches and their change history. Only the
 * telegram registry group: any other group, key or test target is a 404.
 *
 * Separate from the bot page on purpose: settings.integrations, not
 * telegram.bot (a telegram.bot grant must never open the token), and loading
 * this page makes no Telegram call. Security rules: ManagesIntegrationSettings.
 */
#[Layout('layouts::admin')]
class Settings extends Component
{
    use HandlesActionErrors, ManagesIntegrationSettings, WithAdminPagination;

    /**
     * New webhook secret (called from the confirm dialog).
     */
    public function generateWebhookSecret(GenerateWebhookSecret $generate, ConfigureTelegramWebhook $configureWebhook): void
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

        $this->forgetTestResults('telegram');

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('admin.settings.integrations.telegram.webhook_generated'), type: 'success');

        // Plan 14 v2 condition 1: re-register the webhook with the new (committed) secret; failure is an error toast (there is no warning style), never an exception.
        if ($configureWebhook->afterSecretGenerated(auth()->user(), request()->ip()) === ConfigureTelegramWebhook::FAILED) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.telegram_bot.flash.webhook_reset_failed'), type: 'error');
        }
    }

    public function render(): View
    {
        return view('livewire.admin.telegram.settings', [
            'sections' => $this->fieldStates(),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'audits' => $this->audits(),
        ])->title(__('admin.telegram_settings.title'));
    }

    /**
     * @return list<string>
     */
    protected function integrationGroups(): array
    {
        return ['telegram'];
    }

    /**
     * @return list<string>
     */
    protected function allowedTestTargets(): array
    {
        return ['telegram', 'telegram_chat'];
    }

    protected function returnRouteName(): string
    {
        return 'admin.telegram.settings';
    }

    /**
     * Changes of the Telegram settings only (who / when / what, never a value).
     *
     * @return LengthAwarePaginator<int, SettingsAudit>
     */
    protected function audits(): LengthAwarePaginator
    {
        return $this->paginateAudits(
            SettingsAudit::query()
                ->where('area', SettingsArea::Integrations->value)
                ->where('key', 'like', 'telegram.%'),
        );
    }
}
