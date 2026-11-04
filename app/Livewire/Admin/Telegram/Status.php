<?php

namespace App\Livewire\Admin\Telegram;

use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Support\Telegram\Bot\TelegramStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Telegram → Status (/admin/telegram/status, plan 21 E2): a read-only panel
 * of the bot, its alerts, the queue and the inbox, each row with its state
 * and plain next step. Loading and the 60 s poll read the database, config
 * and the 15-minute snapshot only (cached about 55 s): no Telegram call.
 * "Check now" is the one action that calls Telegram (3 s timeouts), limited
 * to about 3 per minute per user and one run at a time.
 *
 * Also lists the staff inbox audit rows (SettingsArea::TelegramInbox, T21-35,
 * plan 21 A7): key, action, who and when, never the IP or any content.
 *
 * Admin only (telegram.bot), authorized on every request (boot, so Livewire
 * updates and polls are covered too).
 */
#[Layout('layouts::admin')]
class Status extends Component
{
    use WithPagination;

    public const AUDIT_PER_PAGE = 15;

    public function boot(): void
    {
        $this->authorize('telegram.bot');
    }

    public function checkNow(TelegramStatus $status): void
    {
        $this->authorize('telegram.bot');

        $outcome = $status->refresh(auth()->id());

        match ($outcome) {
            TelegramStatus::REFRESHED => $this->dispatch('toast', title: __('admin.toast.done'), body: __('telegram_status.flash.refreshed'), type: 'success'),
            TelegramStatus::LIMITED => $this->dispatch('toast', title: __('admin.toast.error'), body: __('telegram_status.flash.limited', ['seconds' => TelegramStatus::retryAfter(auth()->id())]), type: 'error'),
            TelegramStatus::BUSY => $this->dispatch('toast', title: __('admin.toast.error'), body: __('telegram_status.flash.busy'), type: 'error'),
            TelegramStatus::UNREACHABLE => $this->dispatch('toast', title: __('admin.toast.error'), body: __('telegram_status.flash.unreachable'), type: 'error'),
            default => $this->dispatch('toast', title: __('admin.toast.error'), body: __('telegram_status.flash.failed'), type: 'error'),
        };
    }

    public function render(TelegramStatus $status): View
    {
        try {
            $result = $status->result();
        } catch (Throwable $exception) {
            report($exception);
            $result = ['rows' => [], 'counts' => null, 'checked_at' => null];
        }

        return view('livewire.admin.telegram.status', ['result' => $result, 'links' => TelegramStatus::links(), 'inboxAudits' => $this->inboxAudits()])
            ->title(__('telegram_status.title'));
    }

    /**
     * The manager's name comes by a join, not an eager load: with the page's count
     * this stays at two queries per poll (T21-7).
     *
     * @return LengthAwarePaginator<int, SettingsAudit>
     */
    private function inboxAudits(): LengthAwarePaginator
    {
        return SettingsAudit::query()
            ->leftJoin('users', 'users.id', '=', 'settings_audits.user_id')
            ->where('settings_audits.area', SettingsArea::TelegramInbox->value)
            ->latest('settings_audits.created_at')
            ->latest('settings_audits.id')
            ->paginate(self::AUDIT_PER_PAGE, ['settings_audits.id', 'settings_audits.key', 'settings_audits.action', 'settings_audits.created_at', 'users.name as user_name'], 'auditPage');
    }
}
