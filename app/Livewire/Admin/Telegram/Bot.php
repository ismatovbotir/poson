<?php

namespace App\Livewire\Admin\Telegram;

use App\Actions\Telegram\CheckTelegramWebhook;
use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\SetBotCommands;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Telegram → Bot (/admin/telegram/bot, plan 14 v2 §9): bot identity
 * (getMe), the readiness checklist, the kill switch, webhook set / delete
 * and the command menu.
 *
 * Loading makes no Telegram call: mount() evaluates the database-only
 * checklist items; getMe / getWebhookInfo run from wire:init="refresh"
 * (10 per minute per user, 5 s timeout, 3 s connect timeout).
 *
 * Admin only (telegram.bot), authorized on every request (boot) and before
 * each write. Every write needs a password confirmation from the last 15
 * minutes and is audited by its action (condition 16). The displayed
 * checklist is informational: SetBotEnabled re-evaluates it server-side on
 * every enable (TG-37). Disabling needs only the password.
 */
#[Layout('layouts::admin')]
class Bot extends Component
{
    public const AUDIT_LIMIT = 10;

    public const TIMEOUT = 5;

    public const CONNECT_TIMEOUT = 3;

    public const REFRESHES_PER_MINUTE = 10;

    /**
     * getMe essentials: username, id, privacy mode (null: not loaded / failed).
     *
     * @var array{username: string|null, id: int|null, privacy: bool|null}|null
     */
    #[Locked]
    public ?array $bot = null;

    /**
     * Webhook state without the URL itself.
     *
     * @var array{set: bool, matches: bool, pending: int, last_error_at: int|null}|null
     */
    #[Locked]
    public ?array $webhook = null;

    /**
     * Item => ok; null = not checked yet (webhook before the first refresh).
     *
     * @var array<string, bool|null>
     */
    #[Locked]
    public array $checklist = [];

    /**
     * Whether the Telegram calls have run at least once.
     */
    #[Locked]
    public bool $loaded = false;

    /**
     * Error code of the last failed Telegram call while loading (HTTP code / timeout).
     */
    #[Locked]
    public ?string $loadError = null;

    public function boot(): void
    {
        $this->authorize('telegram.bot');
    }

    public function mount(BotChecklist $checklist): void
    {
        $this->checklist = $checklist->local();
    }

    /**
     * Read getMe and getWebhookInfo (wire:init and the Refresh button) and
     * re-evaluate the checklist. Limited per user.
     */
    public function refresh(): void
    {
        $this->authorize('telegram.bot');

        $key = 'telegram-bot-refresh:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::REFRESHES_PER_MINUTE)) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.telegram_bot.errors.too_many_refreshes', ['seconds' => RateLimiter::availableIn($key)]), type: 'error');

            return;
        }

        RateLimiter::hit($key, 60);
        $this->load();
    }

    public function enable(SetBotEnabled $setBotEnabled): void
    {
        $this->write(fn () => $setBotEnabled->enable(auth()->user(), request()->ip())
            ? __('admin.telegram_bot.flash.enabled') : __('admin.telegram_bot.flash.unchanged'));
    }

    public function disable(SetBotEnabled $setBotEnabled): void
    {
        $this->write(fn () => $setBotEnabled->disable(auth()->user(), request()->ip())
            ? __('admin.telegram_bot.flash.disabled') : __('admin.telegram_bot.flash.unchanged'));
    }

    public function setWebhook(ConfigureTelegramWebhook $configure): void
    {
        $this->write(function () use ($configure): string {
            $configure->set(auth()->user(), request()->ip());

            return __('admin.telegram_bot.flash.webhook_set');
        });
    }

    public function deleteWebhook(ConfigureTelegramWebhook $configure): void
    {
        $this->write(function () use ($configure): string {
            $configure->delete(auth()->user(), request()->ip());

            return __('admin.telegram_bot.flash.webhook_deleted');
        });
    }

    public function setCommands(SetBotCommands $setCommands): void
    {
        $this->write(function () use ($setCommands): string {
            $setCommands->handle(auth()->user(), request()->ip());

            return __('admin.telegram_bot.flash.commands_set');
        });
    }

    /**
     * Authorize, require a recent password, run the write, toast, reload.
     *
     * @param  callable(): string  $write  Returns the success message
     */
    protected function write(callable $write): void
    {
        $this->authorize('telegram.bot');

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.telegram.bot')), navigate: false);

            return;
        }

        try {
            $message = $write();
            $this->dispatch('toast', title: __('admin.toast.done'), body: $message, type: 'success');
        } catch (TelegramBotException $exception) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: $exception->translated(), type: 'error');
        }

        $this->load();
    }

    protected function load(): void
    {
        $client = app(TelegramClient::class)->withTimeout(self::TIMEOUT, self::CONNECT_TIMEOUT);
        $this->bot = null;
        $this->webhook = null;
        $this->loadError = null;
        $info = null;

        if ($client->isConfigured()) {
            try {
                $me = $client->getMe();
                $this->bot = [
                    'username' => is_string($me['username'] ?? null) ? $me['username'] : null,
                    'id' => is_int($me['id'] ?? null) ? $me['id'] : null,
                    'privacy' => is_bool($me['can_read_all_group_messages'] ?? null) ? ! $me['can_read_all_group_messages'] : null,
                ];

                $info = $client->getWebhookInfo();
                $expected = ConfigureTelegramWebhook::expectedUrl();
                $url = is_string($info['url'] ?? null) ? $info['url'] : '';
                $this->webhook = [
                    'set' => $url !== '',
                    'matches' => $expected !== null && $url !== '' && hash_equals($expected, $url),
                    'pending' => is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : 0,
                    'last_error_at' => is_int($info['last_error_date'] ?? null) ? $info['last_error_date'] : null,
                ];
            } catch (TelegramException $exception) {
                $this->loadError = $exception->code();
                $info = ['url' => ''];
            }
        }

        $this->checklist = app(BotChecklist::class)->evaluate($info ?? ['url' => '']);
        $this->loaded = true;
    }

    /**
     * Recent bot page changes (who / when / what, never a value).
     *
     * @return Collection<int, SettingsAudit>
     */
    protected function audits(): Collection
    {
        return SettingsAudit::query()
            ->where('area', SettingsArea::TelegramBot->value)
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::AUDIT_LIMIT)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.admin.telegram.bot', [
            'enabled' => BotSwitch::isEnabled(),
            'expectedUrl' => ConfigureTelegramWebhook::expectedUrl(),
            'ready' => BotChecklist::failing($this->checklist) === [],
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'problems' => CheckTelegramWebhook::openProblems(),
            'audits' => $this->audits(),
        ])->title(__('admin.telegram_bot.title'));
    }
}
