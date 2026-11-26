<?php

namespace App\Livewire\Admin\Telegram;

use App\Actions\Telegram\MiniApp\WarmTmaCatalog;
use App\Actions\Telegram\RequestMiniAppMenuSync;
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
use App\Support\Catalog\CategoryFacetCache;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\MiniAppChecklist;
use App\Support\Telegram\MiniApp\MiniAppMenuSync;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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
 * confirmation from the last 15 minutes; enable and sync share a limit of
 * TOGGLES_PER_WINDOW per admin per TOGGLE_WINDOW seconds (MA-38, hit
 * outside the transaction). Disable is never limited (the kill switch must
 * not fail closed). The actions re-check permission, password and (enable)
 * the checklist themselves (MA-34). "Check bot" (getMe, stores the
 * username) is limited like the bot page refresh.
 *
 * Plan 45 B / MA-52: "Rebuild catalog cache" bumps the cache version and
 * refills the Mini App facet cache synchronously (WarmTmaCatalog, under the
 * lock shared with the scheduled warm). No arguments, no password step,
 * WARMS_PER_WINDOW per admin per WARM_WINDOW seconds (the tma-warm limiter,
 * hit inside the method: Livewire bypasses route throttles), no HTTP or
 * Telegram call, nothing logged.
 */
#[Layout('layouts::admin')]
class MiniApp extends Component
{
    public const AUDIT_LIMIT = 10;

    public const TOGGLES_PER_WINDOW = 3;

    public const TOGGLE_WINDOW = 600;

    public const CHECKS_PER_MINUTE = Bot::REFRESHES_PER_MINUTE;

    public const ORDER_DAYS = 30;

    public const WARMS_PER_WINDOW = 3;

    public const WARM_WINDOW = 600;

    /**
     * Display copy of the checklist, evaluated on mount and after each action
     * (not on every render). Informational only: SetMiniAppEnabled evaluates
     * it again on the server on every enable.
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $checklist = [];

    public function boot(): void
    {
        $this->authorize('telegram.bot');
    }

    public function mount(MiniAppChecklist $checklist): void
    {
        $this->checklist = $checklist->evaluate();
    }

    public function enable(SetMiniAppEnabled $setMiniAppEnabled): void
    {
        $this->write(fn (): string => $setMiniAppEnabled->enable(auth()->user(), request()->ip())
            ? __('admin.telegram_mini_app.flash.enabled') : __('admin.telegram_mini_app.flash.unchanged'), limited: true);
    }

    /**
     * The kill switch: authorized and password-confirmed, but never rate-limited
     * (it must not fail closed; security review M6).
     */
    public function disable(SetMiniAppEnabled $setMiniAppEnabled): void
    {
        $this->write(fn (): string => $setMiniAppEnabled->disable(auth()->user(), request()->ip())
            ? __('admin.telegram_mini_app.flash.disabled') : __('admin.telegram_mini_app.flash.unchanged'), limited: false);
    }

    /**
     * "Sync menu buttons": a new fan-out generation (the same job as a toggle), audited.
     */
    public function syncMenus(RequestMiniAppMenuSync $requestSync): void
    {
        $this->write(function () use ($requestSync): string {
            $requestSync->handle(auth()->user(), request()->ip());

            return __('admin.telegram_mini_app.flash.sync_queued');
        }, limited: true);
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
     * "Rebuild catalog cache" (MA-52): Admin only, limited, synchronous.
     */
    public function rebuildCatalogCache(): void
    {
        $this->authorize('telegram.bot');
        $t = 'admin.telegram_mini_app.catalog_cache.';
        $key = 'tma-warm:'.auth()->id();

        if (RateLimiter::hit($key, self::WARM_WINDOW) > self::WARMS_PER_WINDOW) {
            RateLimiter::decrement($key);
            $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'too_many', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]), type: 'error');

            return;
        }

        $result = app(WarmTmaCatalog::class)->handle(bump: true);

        if ($result === null) {
            RateLimiter::decrement($key);
            $this->dispatch('toast', title: __('admin.toast.error'), body: __($t.'busy'), type: 'error');

            return;
        }

        $this->dispatch('toast', title: __('admin.toast.done'), body: __($t.'rebuilt', ['entries' => $result['entries']]), type: 'success');
    }

    /**
     * Authorize, require a recent password, apply the toggle limit (enable and
     * sync only), run the write, toast, refresh the checklist.
     *
     * The limit is one atomic hit (the cache increment returns the new count);
     * over the limit the hit is taken back and nothing runs. A refused enable
     * (failing checklist) also gives its slot back.
     *
     * @param  callable(): string  $write  Returns the success message
     */
    protected function write(callable $write, bool $limited): void
    {
        $this->authorize('telegram.bot');

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.telegram.mini-app')), navigate: false);

            return;
        }

        $key = 'telegram-mini-app-toggle:'.auth()->id();

        if ($limited && RateLimiter::hit($key, self::TOGGLE_WINDOW) > self::TOGGLES_PER_WINDOW) {
            RateLimiter::decrement($key);
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.telegram_mini_app.errors.too_many_toggles', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))]), type: 'error');

            return;
        }

        try {
            $this->dispatch('toast', title: __('admin.toast.done'), body: $write(), type: 'success');
        } catch (TelegramBotException $exception) {
            if ($limited && $exception->reason === 'mini_app_not_ready') {
                RateLimiter::decrement($key);
            }

            $this->dispatch('toast', title: __('admin.toast.error'), body: $exception->translated(), type: 'error');
        }

        $this->checklist = app(MiniAppChecklist::class)->evaluate();
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
            ->whereIn('key', [MiniAppSwitch::KEY, MiniAppMenuSync::STATE_KEY])
            ->with('user:id,name')
            ->latest('id')
            ->limit(self::AUDIT_LIMIT)
            ->get();
    }

    public function render(MiniAppChecklist $checklist): View
    {
        $items = $this->checklist;

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
            'catalogWarmed' => CategoryFacetCache::warmedAt(),
        ])->title(__('admin.telegram_mini_app.title'));
    }
}
