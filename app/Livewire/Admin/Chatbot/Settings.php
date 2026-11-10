<?php

namespace App\Livewire\Admin\Chatbot;

use App\Actions\Chat\SaveChatbotSettings;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Chatbot\SettingsForm;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Chat bot → Settings (plan 28 C2, chatbot.settings, CB-13): texts, working
 * hours, hand-off target and widget options for Admin and Content manager.
 * The two channel switches are Admin only and refused unless the privacy gate
 * passes; a Content manager sees their state read-only. Everything is saved
 * by SaveChatbotSettings (which audits to SettingsArea::Chatbot).
 */
#[Layout('layouts::admin')]
class Settings extends Component
{
    use HandlesActionErrors;

    public SettingsForm $form;

    public function mount(): void
    {
        $this->authorize('chatbot.settings');

        $this->form->load();
    }

    public function hydrate(): void
    {
        $this->authorize('chatbot.settings');
    }

    public function save(SaveChatbotSettings $saveChatbotSettings): void
    {
        $this->authorize('chatbot.settings');

        $this->form->validate();

        $data = $this->form->data($this->isAdmin());

        try {
            $changed = $this->runFormAction(fn (): int => $saveChatbotSettings->handle($data, auth()->user(), request()->ip()));
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->form->load();

        $this->dispatch('toast', title: __('admin.toast.saved'), body: $changed > 0 ? __('chatbot_admin.settings.saved') : __('chatbot_admin.settings.unchanged'));
    }

    private function isAdmin(): bool
    {
        return auth()->user()->hasRole(Role::Admin);
    }

    public function render(): View
    {
        $this->authorize('chatbot.settings');

        return view('livewire.admin.chatbot.settings', [
            'isAdmin' => $this->isAdmin(),
            'privacyReady' => ChatSettings::privacyReady(),
            'paused' => [
                'telegram' => ChatbotSwitch::isStored(ChatChannel::Telegram) && ! ChatbotSwitch::isEnabled(ChatChannel::Telegram),
                'web' => ChatbotSwitch::isStored(ChatChannel::Web) && ! ChatbotSwitch::isEnabled(ChatChannel::Web),
            ],
            'webReady' => (bool) config('chatbot.web_ready'),
            'days' => ChatSettings::DAYS,
        ])->title(__('chatbot_admin.settings.title'));
    }
}
