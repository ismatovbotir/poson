<?php

namespace App\Livewire\Admin\Telegram;

use App\Actions\Telegram\SetMiniAppEnabled;
use App\Actions\Telegram\TelegramBotException;
use App\Enums\OrderSource;
use App\Enums\SettingsArea;
use App\Models\Order;
use App\Models\SettingsAudit;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\WebAppButton;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\MiniAppChecklist;
use App\Support\Telegram\MiniApp\MiniAppMenuSync;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Telegram → Mini App (/admin/telegram/mini-app, plan 20 §10, M6).
 *
 * Shows the switch (Mini App row AND bot switch), the server-side checklist,
 * the stored bot username (only if valid), the menu button fan-out state
 * (generation, state, times, count) and Mini App orders of the last 30
 * days (Order::real()). Never a token, a getMe response or a Telegram id
 * (MA-39). Loading makes no Telegram call.
 *
 * Admin only (telegram.bot), authorized on every request (boot) and before
 * each write. Enable / disable / "Sync menu buttons" need a password
 * confirmation from the last 15 minutes and share a limit of
 * TOGGLES_PER_WINDOW per admin per TOGGLE_WINDOW seconds (MA-38, hit
 * outside the transaction); SetMiniAppEnabled re-checks permission,
 * password and checklist itself (MA-34). "Check bot" (getMe, stores the
 * username) is limited like the bot page refresh.
 */
#[Layout('layouts::admin')]
class MiniApp extends Component
{
    public const AUDIT_LIMIT = 10;

    public const TOGGLES_PER_WINDOW = 3;

    public const TOGGLE_WINDOW = 600;

    public const CHECKS_PER_MINUTE = Bot::REFRESHES_PER_MINUTE;

    public const ORDER_DAYS = 30;

    public function boot(): void
    {
        $this->authorize('telegram.bot');
    }

    public function enable(SetMiniAppEnabled $setMiniAppEnabled): void
    {
        $this->write(fn (): string => $setMiniAppEnabled->enable(auth()->user(), request()->ip())
            ? __('admin.telegram_mini_app.flash.enabled') : __('admin.telegram_mini_app.flash.unchanged'));
    }

    public function disable(SetMiniAppEnabled $setMiniAppEnabled): void
    {
        $this->write(fn (): string => $setMiniAppEnabled->disable(auth()->user(), request()->ip())
            ? __('admin.telegram_mini_app.flash.disabled') : __('admin.telegram_mini_app.flash.unchanged'));
    }

    /**
     * "Sync menu buttons": a new fan-out generation (the same job as a toggle).
     */
    public function syncMenus(): void
    {
        $this->write(function (): string {
            MiniAppMenuSync::start();

            return __('admin.telegram_mini_app.flash.sync_queued');
        });
    }

    /**
     * "Check bot": getMe, stores the username when it is valid. Limited per user.
     */
    public function checkBot(TelegramClient $client): void
    {
        $this->authorize('telegram.bot');
        $t = 'admin.telegram_mini_app.';
        $key = 'telegram-mini-app-check:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::CHECKS_PER_MINUTE)) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'errors.too_many_checks', ['seconds' => RateLimiter::availableIn($key)]), type: 'error');

            return;
        }

        RateLimiter::hit($key, 60);

        if (! $client->isConfigured()) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'errors.no_token'), type: 'error');

            return;
        }

        try {
            $me = $client->withTimeout(Bot::TIMEOUT, Bot::CONNECT_TIMEOUT)->getMe();
        } catch (TelegramException $exception) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.telegram_bot.errors.telegram_error', ['code' => $exception->code()]), type: 'error');

            return;
        }

        if (! BotUsername::remember($me['username'] ?? null)) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'errors.invalid_username'), type: 'error');

            return;
        }

        $this->dispatch('toast', title: __('admin.toast.done'), body: __($t.'flash.checked'), type: 'success');
    }

    /**
     * Authorize, require a recent password, apply the toggle limit, run the write, toast.
     *
     * @param  callable(): string  $write  Returns the success message
     */
    protected function write(callable $write): void
    {
        $this->authorize('telegram.bot');

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.telegram.mini-app')), navigate: false);

            return;
        }

        $key = 'telegram-mini-app-toggle:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::TOGGLES_PER_WINDOW)) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.telegram_mini_app.errors.too_many_toggles', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]), type: 'error');

            return;
        }

        RateLimiter::hit($key, self::TOGGLE_WINDOW);

        try {
            $this->dispatch('toast', title: __('admin.toast.done'), body: $write(), type: 'success');
        } catch (TelegramBotException $exception) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: $exception->translated(), type: 'error');
        }
    }

    /**
     * Recent Mini App switch changes (who / when / what, never a value).
     *
     * @return Collection<int, SettingsAudit>
     */
    protected function audits(): Collection
    {
        return SettingsAudit::query()
            ->where('area', SettingsArea::TelegramBot->value)
            ->where('key', MiniAppSwitch::KEY)
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::AUDIT_LIMIT)
            ->get();
    }

    public function render(MiniAppChecklist $checklist): View
    {
        $items = $checklist->evaluate();

        return view('livewire.admin.telegram.mini-app', [
            'enabled' => MiniAppSwitch::isEnabled(),
            'storedOn' => MiniAppSwitch::isStoredOn(),
            'botOn' => BotSwitch::isEnabled(),
            'checklist' => $items,
            'info' => $checklist->info(),
            'ready' => MiniAppChecklist::failing($items) === [],
            'username' => BotUsername::get(),
            'sync' => MiniAppMenuSync::state(),
            'entryUrl' => WebAppButton::miniApp('Catalog')?->url,
            'orders' => Order::query()->real()->where('source', OrderSource::Telegram)->where('created_at', '>=', now()->subDays(self::ORDER_DAYS))->count(),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'audits' => $this->audits(),
        ])->title(__('admin.telegram_mini_app.title'));
    }
}
