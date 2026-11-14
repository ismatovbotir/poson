<?php

namespace App\Livewire\Admin\Chatbot;

use App\Actions\Chat\SaveChatbotSettings;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Chatbot\SettingsForm;
use App\Support\Chat\AiSwitch;
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
 *
 * AI answers (plan 30, AI-1, AI-24, P2): the API key status is a bool
 * computed on the server in render(), never a property and never the value.
 * Only an Admin switches AI on, changes the daily caps and edits the
 * instructions; a Content manager sees them read-only and may only switch AI
 * off. The action enforces the same rules.
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
            'aiKeyConfigured' => AiSwitch::keyConfigured(),
            'aiLimits' => [
                'model' => (string) config('chatbot.ai.model'),
                'max_tokens' => (int) config('chatbot.ai.max_tokens'),
                'direct_min_score' => (int) config('chatbot.ai.direct_min_score'),
                'per_minute_user' => (int) config('chatbot.ai.limits.per_minute_user'),
                'per_day_user' => (int) config('chatbot.ai.limits.per_day_user'),
                'per_day_anonymous' => (int) config('chatbot.ai.limits.per_day_anonymous'),
                'per_minute_global' => (int) config('chatbot.ai.limits.per_minute_global'),
            ],
        ])->title(__('chatbot_admin.settings.title'));
    }
}
