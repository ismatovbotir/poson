<?php

namespace App\Livewire\Admin\Telegram;

use App\Support\Telegram\Bot\TelegramStatus;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Telegram → Status (/admin/telegram/status, plan 21 E2): a read-only panel
 * of the bot, its alerts, the queue and the inbox, each row with its state
 * and plain next step. Loading and the 60 s poll read the database, config
 * and the 15-minute snapshot only (cached about 55 s): no Telegram call.
 * "Check now" is the one action that calls Telegram (3 s timeouts), limited
 * to about 3 per minute per user and one run at a time.
 *
 * Admin only (telegram.bot), authorized on every request (boot, so Livewire
 * updates and polls are covered too).
 */
#[Layout('layouts::admin')]
class Status extends Component
{
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
            default => $this->dispatch('toast', title: __('admin.toast.error'), body: __('telegram_status.flash.failed'), type: 'error'),
        };
    }

    public function render(TelegramStatus $status): View
    {
        return view('livewire.admin.telegram.status', ['result' => $status->result()])
            ->title(__('telegram_status.title'));
    }
}
