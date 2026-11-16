<?php

namespace App\Livewire\Admin\Chatbot;

use App\Actions\Chat\Knowledge\KnowledgeOverview;
use App\Actions\Chat\Knowledge\QueueKnowledgeRebuild;
use App\Actions\Chat\SaveChatbotSettings;
use App\Enums\AiUsageChannel;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Chatbot\SettingsForm;
use App\Models\ChatAiUsageDaily;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\WorkingHours;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
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
 *
 * AI product wizard (plan 32, PW-24, §14 plan change 8): only an Admin
 * switches it on or off and changes the stage caps; switching on needs the
 * key. A Content manager sees the state, caps and usage read-only.
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

    /**
     * "Rebuild now" of the website knowledge (plan 33 K-11): chatbot.settings, one request per five minutes.
     */
    public function rebuildKnowledge(QueueKnowledgeRebuild $rebuild): void
    {
        $this->authorize('chatbot.settings');

        $started = $rebuild->handle(auth()->user(), request()->ip());

        unset($this->knowledge);

        $this->dispatch('toast', title: __('chatbot_admin.settings.knowledge.title'), body: $started ? __('chatbot_admin.settings.knowledge.rebuild_started') : __('chatbot_admin.settings.knowledge.rebuild_running'));
    }

    /**
     * The "Website knowledge" card data, once per request (cleared after a rebuild request).
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function knowledge(): array
    {
        return app(KnowledgeOverview::class)->handle();
    }

    private function isAdmin(): bool
    {
        return auth()->user()->hasRole(Role::Admin);
    }

    /**
     * Plan 32: the AI product wizard card — stage models, efforts and limits
     * (read-only, from config) and the usage of the last 14 days per stage
     * channel with the USD estimate (Stats::prices). Counts only.
     *
     * @return array{stages: array<string, array{model: string, effort: string, max_tokens: int, per_hour_user: int, per_hour_global: int}>, usage: array<string, array{requests: int, input_tokens: int, output_tokens: int, usd: float}>, days: int}
     */
    private function wizard(): array
    {
        $days = 14;
        $from = now(WorkingHours::TIMEZONE)->subDays($days - 1)->toDateString();
        $stages = [];
        $usage = [];

        foreach (['vision' => AiUsageChannel::AdminVision, 'write' => AiUsageChannel::AdminWrite] as $stage => $channel) {
            $stages[$channel->value] = [
                'model' => (string) config("chatbot.ai.product.{$stage}.model"),
                'effort' => (string) config("chatbot.ai.product.{$stage}.effort"),
                'max_tokens' => (int) config("chatbot.ai.product.{$stage}.max_tokens"),
                'per_hour_user' => (int) config("chatbot.ai.product.limits.{$stage}_per_hour_user"),
                'per_hour_global' => (int) config("chatbot.ai.product.limits.{$stage}_per_hour_global"),
            ];

            $row = ChatAiUsageDaily::query()->where('channel', $channel->value)->where('day', '>=', $from)
                ->selectRaw('coalesce(sum(requests), 0) as requests, coalesce(sum(input_tokens), 0) as input_tokens, coalesce(sum(output_tokens), 0) as output_tokens, coalesce(sum(cache_read_tokens), 0) as cache_read_tokens, coalesce(sum(cache_write_tokens), 0) as cache_write_tokens')
                ->toBase()->first();
            $usage[$channel->value] = [
                'requests' => (int) ($row->requests ?? 0),
                'input_tokens' => (int) ($row->input_tokens ?? 0),
                'output_tokens' => (int) ($row->output_tokens ?? 0),
                'usd' => Stats::usd($channel, (int) ($row->input_tokens ?? 0), (int) ($row->output_tokens ?? 0), (int) ($row->cache_read_tokens ?? 0), (int) ($row->cache_write_tokens ?? 0)),
            ];
        }

        return ['stages' => $stages, 'usage' => $usage, 'days' => $days];
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
            'knowledge' => $this->knowledge,
            'webReady' => (bool) config('chatbot.web_ready'),
            'days' => ChatSettings::DAYS,
            'aiKeyConfigured' => AiSwitch::keyConfigured(),
            'wizard' => $this->wizard(),
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
