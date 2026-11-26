<?php

namespace App\Livewire\Admin\Office;

use App\Actions\Chat\RecordAiUsage;
use App\Actions\Office\SaveOfficeSettings;
use App\Actions\Office\SetOfficeOwners;
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
use App\Support\Office\OwnerCandidates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Virtual office → Settings (/admin/office/settings, plan 41 §8.5,
 * office.settings = Admin only): ingest, AI and auto-lead switches (all
 * stored by SaveOfficeSettings, which validates and audits), debounce,
 * per-chat cap and the daily caps per channel (≤ 5 × default), the
 * connection status and "Delete all business data" (password). The API
 * key status is a bool computed in render().
 *
 * The owner picker (plan 44 §1, OG-3): chips of the selected owners, a
 * server-side candidate search (OwnerCandidates), "Add by ID" and Allow /
 * Remove on the Connection rows. Every change goes straight to
 * SetOfficeOwners (Admin, password, ≤ 10 ids, refused while
 * OFFICE_OWNER_TELEGRAM_IDS locks the list). The selected ids are never a
 * public property: they are read from OfficeSettings on every request.
 */
#[Layout('layouts::admin')]
class Settings extends Component
{
    public bool $ingestEnabled = false;

    public bool $aiEnabled = false;

    public bool $autoLead = true;

    /** Owner picker search term (≥ 2 characters, OwnerCandidates). */
    public string $ownerSearch = '';

    /** "Add by ID" input. */
    public string $manualOwnerId = '';

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
            'debounce_minutes' => $this->debounceMinutes,
            'per_chat_daily' => $this->perChatDaily,
            'caps' => $this->caps,
        ], request()->ip());

        $this->load();

        $this->dispatch('toast', title: __('admin.toast.saved'), body: $changed > 0 ? __('office.settings.saved') : __('office.settings.unchanged'), type: 'success');
    }

    /**
     * Add an owner from the candidate list or by id (validated by SetOfficeOwners).
     */
    public function addOwner(SetOfficeOwners $owners, mixed $telegramId): void
    {
        $this->authorize('office.settings');

        $this->changeOwners($owners, SetOfficeOwners::ADD, $telegramId, __('office.settings.owner_added'));
    }

    public function addManualOwner(SetOfficeOwners $owners): void
    {
        $this->authorize('office.settings');

        $this->validate(['manualOwnerId' => ['required', 'regex:/^[1-9]\d{0,15}$/D']], [], ['manualOwnerId' => __('office.settings.owner_id')]);

        if ($this->changeOwners($owners, SetOfficeOwners::ADD, (int) $this->manualOwnerId, __('office.settings.owner_added'))) {
            $this->manualOwnerId = '';
        }
    }

    public function removeOwner(SetOfficeOwners $owners, mixed $telegramId): void
    {
        $this->authorize('office.settings');

        $this->changeOwners($owners, SetOfficeOwners::REMOVE, $telegramId, __('office.settings.owner_removed'));
    }

    /**
     * Allow the owner of a Connection row (the id is read from the row, never from the browser).
     */
    public function allowConnection(SetOfficeOwners $owners, mixed $connectionId): void
    {
        $this->authorize('office.settings');

        $this->changeOwners($owners, SetOfficeOwners::ADD, $this->connectionOwner($connectionId), __('office.settings.owner_added'));
    }

    public function removeConnection(SetOfficeOwners $owners, mixed $connectionId): void
    {
        $this->authorize('office.settings');

        $this->changeOwners($owners, SetOfficeOwners::REMOVE, $this->connectionOwner($connectionId), __('office.settings.owner_removed'));
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
        $ownerIds = OfficeSettings::ownerIds();

        return view('livewire.admin.office.settings', [
            'keyConfigured' => AiSwitch::keyConfigured(),
            'ownersLocked' => OfficeSettings::ownerIdsLocked(),
            'owners' => OwnerCandidates::resolve($ownerIds),
            'ownerIdList' => $ownerIds,
            'candidates' => OfficeSettings::ownerIdsLocked() ? [] : OwnerCandidates::search($this->ownerSearch, $ownerIds),
            'canAddOwner' => count($ownerIds) < SetOfficeOwners::MAX_IDS,
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
            'channels' => AiUsageChannel::office(),
            'maxCaps' => collect(AiUsageChannel::office())->mapWithKeys(fn (AiUsageChannel $channel): array => [$channel->value => OfficeSettings::maxCaps($channel)])->all(),
            // Today's usage against the caps (Tashkent day), counts only.
            'usage' => ChatAiUsageDaily::query()->where('day', RecordAiUsage::day())->whereIn('channel', array_column(AiUsageChannel::office(), 'value'))
                ->get(['channel', 'requests', 'billed_tokens', 'reserved_tokens', 'capped'])->keyBy(fn (ChatAiUsageDaily $row): string => $row->channel->value),
            'activeCaps' => collect(AiUsageChannel::office())->mapWithKeys(fn (AiUsageChannel $channel): array => [$channel->value => OfficeSettings::caps($channel)])->all(),
            'connections' => BusinessConnection::query()->orderByDesc('updated_at')->limit(10)->get(['id', 'owner_telegram_id', 'owner_first_name', 'owner_username', 'is_enabled', 'is_allowed', 'can_reply', 'connected_at', 'disabled_at', 'updated_at']),
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
        $this->debounceMinutes = OfficeSettings::debounceMinutes();
        $this->perChatDaily = OfficeSettings::perChatDaily();
        $this->caps = collect(AiUsageChannel::office())->mapWithKeys(fn (AiUsageChannel $channel): array => [$channel->value => OfficeSettings::caps($channel)])->all();
    }

    /**
     * Run SetOfficeOwners with one delta; without a recent password go to the confirm page first.
     *
     * @param  SetOfficeOwners::ADD|SetOfficeOwners::REMOVE  $operation
     */
    private function changeOwners(SetOfficeOwners $owners, string $operation, mixed $telegramId, string $message): bool
    {
        if (! OfficeSettings::ownerIdsLocked() && ! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.office.settings')), navigate: false);

            return false;
        }

        $changed = $owners->handle($this->user(), $operation, $telegramId, request()->ip());
        $this->ownerSearch = '';

        $this->dispatch('toast', title: __('admin.toast.saved'), body: $changed ? $message : __('office.settings.unchanged'), type: 'success');

        return true;
    }

    /**
     * The owner id of a stored Connection row (404 for an unknown row).
     */
    private function connectionOwner(mixed $connectionId): int
    {
        $id = filter_var($connectionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        abort_if($id === false, 404);

        return BusinessConnection::query()->whereKey($id)->valueOrFail('owner_telegram_id');
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
