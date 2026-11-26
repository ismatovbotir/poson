<?php

namespace App\Livewire\Admin\Office\Groups;

use App\Actions\Office\IgnoreGroup;
use App\Actions\Office\OfficeStaff;
use App\Actions\Office\TrackGroup;
use App\Actions\Office\UntrackGroup;
use App\Enums\GroupStatus;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\CustomerCandidates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Virtual office → Telegram groups (/admin/office/groups, plan 44 §2, OG-7):
 * tabs Untracked / Tracked / Ignored over TelegramGroup::visibleTo(), so the
 * list matches the group URLs: Admin sees every group, anyone else only the
 * tracked groups assigned to them (no other tabs). Track (assignee, optional
 * customer and the required privacy-note tick, OG-4) and Ignore (title
 * nulled, OG-12) are office.mark = Admin only. Only titles, counters and the
 * bot state are read: never a member or a message.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithAdminPagination;

    public const PER_PAGE = 25;

    #[Url(except: 'untracked')]
    public string $tab = 'untracked';

    /** The group whose Track dialog is open. */
    #[Locked]
    public ?int $trackingId = null;

    public ?int $assigneeId = null;

    public ?int $customerId = null;

    public string $customerSearch = '';

    public bool $privacyNote = false;

    public function mount(): void
    {
        $this->authorize('viewAny', TelegramGroup::class);

        if (! $this->seesAll()) {
            $this->tab = GroupStatus::Tracked->value;
        }
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', TelegramGroup::class);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->closeTrack();
    }

    public function openTrack(int $groupId): void
    {
        $group = $this->findGroup($groupId);
        $this->authorize('mark', $group);

        $this->resetErrorBag();
        $this->reset('assigneeId', 'customerId', 'customerSearch', 'privacyNote');
        $this->trackingId = $group->id;
    }

    public function closeTrack(): void
    {
        $this->reset('trackingId', 'assigneeId', 'customerId', 'customerSearch', 'privacyNote');
        $this->resetErrorBag();
    }

    public function pickCustomer(int $customerId): void
    {
        $this->authorize('office.mark');

        $this->customerId = $customerId;
        $this->customerSearch = '';
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
    }

    public function track(TrackGroup $track): void
    {
        if ($this->trackingId === null) {
            return;
        }

        $group = $this->findGroup($this->trackingId);
        $this->authorize('mark', $group);

        $this->resetErrorBag();

        if ($this->assigneeId === null) {
            $this->addError('assigneeId', __('office.errors.assignee_invalid'));

            return;
        }

        $track->handle($this->user(), $group, $this->assigneeId, $this->customerId, $this->privacyNote, request()->ip());

        $this->closeTrack();
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.groups.tracked'), type: 'success');
    }

    public function ignore(int $groupId, IgnoreGroup $ignore): void
    {
        $group = $this->findGroup($groupId);
        $this->authorize('mark', $group);

        $ignore->handle($this->user(), $group, request()->ip());

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.groups.ignored'), type: 'success');
    }

    public function untrack(int $groupId, UntrackGroup $action): void
    {
        $group = $this->findGroup($groupId);
        $this->authorize('mark', $group);

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.office.groups', ['tab' => GroupStatus::Tracked->value])), navigate: false);

            return;
        }

        $action->handle($this->user(), $group, request()->ip());

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('office.groups.untracked'), type: 'success');
    }

    public function render(): View
    {
        $user = $this->user();
        $seesAll = $this->seesAll();
        $status = $seesAll ? (GroupStatus::tryFrom($this->tab) ?? GroupStatus::Untracked) : GroupStatus::Tracked;
        $this->tab = $status->value;

        $groups = TelegramGroup::query()
            ->visibleTo($user)
            ->where('telegram_groups.status', $status->value)
            ->select(['id', 'title', 'type', 'status', 'bot_status', 'bot_is_admin', 'seen_count', 'last_message_at', 'assignee_id'])
            ->with('assignee:id,name')
            ->withCount('members')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $canMark = $user->can('office.mark');
        $tracking = $canMark && $this->trackingId !== null ? TelegramGroup::query()->whereKey($this->trackingId)->first(['id', 'title']) : null;

        return view('livewire.admin.office.groups.index', [
            'groups' => $groups,
            'status' => $status,
            'seesAll' => $seesAll,
            'canMark' => $canMark,
            'tracking' => $tracking,
            'assignable' => $tracking ? OfficeStaff::assignable() : collect(),
            'candidates' => $tracking ? CustomerCandidates::search($this->customerSearch) : [],
            'customerName' => $tracking ? CustomerCandidates::name($this->customerId) : null,
        ])->title(__('office.groups.title'));
    }

    private function seesAll(): bool
    {
        return $this->user()->can('office.mark') || $this->user()->can('office.chats-all');
    }

    private function findGroup(int $id): TelegramGroup
    {
        return TelegramGroup::query()->whereKey($id)->firstOrFail();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
