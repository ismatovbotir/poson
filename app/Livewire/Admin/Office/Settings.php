<?php

namespace App\Livewire\Admin\Office;

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Office\SaveOfficeSettings;
use App\Actions\Office\WipeBusinessData;
use App\Enums\AiUsageChannel;
use App\Enums\SettingsArea;
use App\Models\BusinessConnection;
use App\Models\ChatAiUsageDaily;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Chat\AiSwitch;
use App\Support\Office\OfficeSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Virtual office → Settings (/admin/office/settings, plan 41 §8.5,
 * office.settings = Admin only): ingest, AI and auto-lead switches (all
 * stored by SaveOfficeSettings, which validates and audits), the owner
 * allow-list (password; locked by OFFICE_OWNER_TELEGRAM_IDS), debounce,
 * per-chat cap and the daily caps per channel (≤ 5 × default), the
 * connection status (read-only, no ids) and "Delete all business data"
 * (password). The API key status is a bool computed in render().
 */
#[Layout('layouts::admin')]
class Settings extends Component
{
    public bool $ingestEnabled = false;

    public bool $aiEnabled = false;

    public bool $autoLead = true;

    public string $ownerIds = '';

    /** @var int|string|null Untyped like the sibling settings forms: an empty input reaches the action's validation (a message, never a type error). */
    public $debounceMinutes = 10;

    /** @var int|string|null */
    public $perChatDaily = 12;

    /** @var array<string, array{requests: int|string, tokens: int|string}> */
    public array $caps = [];

    public function mount(): void
    {
        $this->authorize('office.settings');

        $this->load();
    }

    public function hydrate(): void
    {
        $this->authorize('office.settings');
    }

    public function save(SaveOfficeSettings $save): void
    {
        $this->authorize('office.settings');

        $changed = $save->handle($this->user(), [
            'ingest_enabled' => $this->ingestEnabled,
            'ai_enabled' => $this->aiEnabled,
            'auto_lead' => $this->autoLead,
            'owner_ids' => $this->ownerIds,
            'debounce_minutes' => $this->debounceMinutes,
            'per_chat_daily' => $this->perChatDaily,
            'caps' => $this->caps,
        ], request()->ip());

        $this->load();

        $this->dispatch('toast', title: __('admin.toast.saved'), body: $changed > 0 ? __('office.settings.saved') : __('office.settings.unchanged'), type: 'success');
    }

    public function wipe(WipeBusinessData $wipe): void
    {
        $this->authorize('office.settings');

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.office.settings')), navigate: false);

            return;
        }

        $wipe->handle($this->user(), request()->ip());
        $this->load();

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('office.settings.wipe_started'), type: 'success');
    }

    public function render(): View
    {
        return view('livewire.admin.office.settings', [
            'keyConfigured' => AiSwitch::keyConfigured(),
            'ownersLocked' => OfficeSettings::ownerIdsLocked(),
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'channels' => AiUsageChannel::office(),
            'maxCaps' => collect(AiUsageChannel::office())->mapWithKeys(fn (AiUsageChannel $channel): array => [$channel->value => OfficeSettings::maxCaps($channel)])->all(),
            // Today's usage against the caps (Tashkent day), counts only.
            'usage' => ChatAiUsageDaily::query()->where('day', RecordAiUsage::day())->whereIn('channel', array_column(AiUsageChannel::office(), 'value'))
                ->get(['channel', 'requests', 'billed_tokens', 'reserved_tokens', 'capped'])->keyBy(fn (ChatAiUsageDaily $row): string => $row->channel->value),
            'activeCaps' => collect(AiUsageChannel::office())->mapWithKeys(fn (AiUsageChannel $channel): array => [$channel->value => OfficeSettings::caps($channel)])->all(),
            'connections' => BusinessConnection::query()->orderByDesc('updated_at')->limit(10)->get(['id', 'is_enabled', 'is_allowed', 'can_reply', 'connected_at', 'disabled_at', 'updated_at']),
            'audits' => SettingsAudit::query()->where('area', SettingsArea::Office->value)->with('user:id,name')->latest('id')->limit(15)->get(),
        ])->title(__('office.settings.title'));
    }

    public function confirmPassword(): void
    {
        $this->authorize('office.settings');

        $this->redirect(PasswordConfirmation::url(route('admin.office.settings')), navigate: false);
    }

    private function load(): void
    {
        OfficeSettings::forget();

        $this->ingestEnabled = OfficeSettings::ingestEnabled();
        $this->aiEnabled = OfficeSettings::aiStored();
        $this->autoLead = OfficeSettings::autoLead();
        $this->ownerIds = implode(', ', OfficeSettings::ownerIds());
        $this->debounceMinutes = OfficeSettings::debounceMinutes();
        $this->perChatDaily = OfficeSettings::perChatDaily();
        $this->caps = collect(AiUsageChannel::office())->mapWithKeys(fn (AiUsageChannel $channel): array => [$channel->value => OfficeSettings::caps($channel)])->all();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
