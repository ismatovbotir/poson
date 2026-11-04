<?php

namespace App\Livewire\Admin\Telegram;

use App\Actions\Telegram\CheckTelegramWebhook;
use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\SetBotEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\TelegramStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Telegram → Bot (/admin/telegram/bot, plan 14 v2 §9): bot identity
 * (getMe), the readiness checklist, the kill switch, webhook set / delete.
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
     * The last chat verification hit a transient Telegram failure (not a verdict).
     */
    #[Locked]
    public bool $chatUnverified = false;

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

    /**
     * "Check now" for the inbox chat: the status panel's own check
     * (TelegramStatus::refresh: rate-limited, 3 s timeouts, cached verdict
     * dropped first). The shown verdict is informational only.
     */
    public function checkChat(TelegramStatus $status): void
    {
        $this->authorize('telegram.bot');

        $t = 'admin.telegram_bot.chat_check.';
        $outcome = $status->refresh(auth()->id());

        if ($outcome === TelegramStatus::REFRESHED) {
            $this->load();
        }

        match ($outcome) {
            TelegramStatus::REFRESHED => $this->dispatch('toast', title: __('admin.toast.done'), body: __($t.'done'), type: 'success'),
            TelegramStatus::LIMITED => $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'limited', ['seconds' => TelegramStatus::retryAfter(auth()->id())]), type: 'error'),
            TelegramStatus::BUSY => $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'busy'), type: 'error'),
            TelegramStatus::UNREACHABLE => $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'unreachable'), type: 'error'),
            default => $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'failed'), type: 'error'),
        };
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

        $checklist = app(BotChecklist::class);
        $this->checklist = $checklist->evaluate($info ?? ['url' => '']);
        $this->chatUnverified = $checklist->chatUnavailable();
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

    /**
     * Problem counts of the status panel (cached there), or null if unavailable.
     *
     * @return array{problems: int, unknown: int}|null
     */
    protected function statusSummary(): ?array
    {
        try {
            return app(TelegramStatus::class)->summary();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Display-only chat verdict from the status snapshot: pass, fail or unknown.
     */
    protected function chatVerdict(): string
    {
        try {
            foreach (app(TelegramStatus::class)->result()['rows'] as $row) {
                if ($row['id'] === 'chat') {
                    return match ($row['state']) {
                        TelegramStatus::OK => 'pass',
                        TelegramStatus::PROBLEM => 'fail',
                        default => 'unknown',
                    };
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return 'unknown';
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
            'statusSummary' => $this->statusSummary(),
            'chatVerdict' => $this->chatVerdict(),
        ])->title(__('admin.telegram_bot.title'));
    }
}
