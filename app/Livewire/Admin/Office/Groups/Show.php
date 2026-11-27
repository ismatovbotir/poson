<?php

namespace App\Livewire\Admin\Office\Groups;

use App\Actions\Office\ForgetGroupMember;
use App\Actions\Office\OfficeStaff;
use App\Actions\Office\QueueGroupAnalysis;
use App\Actions\Office\RecordDraftFeedback;
use App\Actions\Office\RequestGroupAnalysis;
use App\Actions\Office\SetGroupMemberRoles;
use App\Actions\Office\UntrackGroup;
use App\Enums\GroupMemberRole;
use App\Jobs\FetchGroupAdministrators;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Models\TelegramGroupThread;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Office\CustomerCandidates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Virtual office → one Telegram group (/admin/office/groups/{id}, plan 44
 * §2, OG-7, OG-8): header with the bot warning, the member table with the
 * bulk "Set role" (office.mark), "Refresh admins" (throttled), "Forget" per
 * member (password), the threads panel (topic, summary, next step, a
 * copy-only draft that is never sent, the lead link) with "Analyse now"
 * (office.analyse; it only queues, RequestGroupAnalysis applies the gates,
 * caps and throttle) and the read-only transcript, 50 per page (newest page
 * first, each page oldest to newest). The group id is #[Locked]; mount and
 * every method authorize through TelegramGroupPolicy on a fresh row. The
 * member ids of a bulk action are re-queried scoped to this group by the
 * action. A phone is selected and shown only to office.mark or the
 * assignee. Everything is rendered escaped, no autolinks. Nothing here
 * sends anything to Telegram.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    use WithAdminPagination;

    public const MEMBERS_PER_PAGE = 25;

    public const MESSAGES_PER_PAGE = 50;

    public const THREADS_SHOWN = 10;

    #[Locked]
    public int $groupId = 0;

    /** @var array<int, int|string> Ticked member ids (re-validated by the action) */
    public array $selected = [];

    public string $bulkRole = '';

    public ?int $bulkStaffId = null;

    public ?int $bulkCustomerId = null;

    public string $customerSearch = '';

    /** @var array<int, array{outcome?: string, reason?: string, final_text?: string, note?: string}> Draft feedback forms per thread id (plan 46) */
    public array $feedback = [];

    public function mount(TelegramGroup $group): void
    {
        $this->authorize('view', $group);

        $this->groupId = $group->id;
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->group());
    }

    /**
     * Leaving a members page clears the ticks: they only count for what is on screen.
     */
    public function updatingPaginators(int $page, string $pageName): void
    {
        if ($pageName === 'members') {
            $this->selected = [];
        }
    }

    public function updatedBulkRole(): void
    {
        $this->reset('bulkStaffId', 'bulkCustomerId', 'customerSearch');
    }

    public function refreshAdmins(): void
    {
        $group = $this->group();
        $this->authorize('mark', $group);

        if (! $group->isTracked()) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('office.groups.errors.not_tracked'), type: 'error');

            return;
        }

        $queued = FetchGroupAdministrators::dispatchFor($group);

        $this->dispatch('toast', title: $queued ? __('admin.toast.done') : __('office.groups.show.refresh_throttled_title'), body: $queued ? __('office.groups.show.refresh_queued') : __('office.groups.show.refresh_throttled'), type: $queued ? 'success' : 'info');
    }

    public function analyse(RequestGroupAnalysis $request): void
    {
        $group = $this->group();
        $this->authorize('analyse', $group);

        $request->handle($this->user(), $group);

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('office.show.analysis_queued'), type: 'success');
    }

    /**
     * Plan 46: what was done with a thread's draft. The group comes from the locked id; the thread and its
     * analysis are re-read and checked by RecordDraftFeedback (a thread of another group is stale).
     */
    public function submitFeedback(int $threadId, RecordDraftFeedback $record): void
    {
        $group = $this->group();
        $this->authorize('analyse', $group);

        $thread = TelegramGroupThread::query()->where('telegram_group_id', $group->id)->whereKey($threadId)->first();
        $this->feedback = array_intersect_key($this->feedback, [$threadId => true]);

        $record->handle($this->user(), $group, [...($this->feedback[$threadId] ?? []), 'thread_id' => $threadId, 'analysis_id' => $thread?->last_analysis_id]);

        unset($this->feedback[$threadId]);
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.feedback.saved'), type: 'success');
    }

    public function pickCustomer(int $customerId): void
    {
        $this->authorize('mark', $this->group());

        $this->bulkCustomerId = $customerId;
        $this->customerSearch = '';
    }

    public function clearCustomer(): void
    {
        $this->bulkCustomerId = null;
    }

    public function setRole(SetGroupMemberRoles $action): void
    {
        $group = $this->group();
        $this->authorize('mark', $group);

        $this->resetErrorBag();

        $role = GroupMemberRole::tryFrom($this->bulkRole);

        if ($role === null || $role === GroupMemberRole::Bot) {
            $this->addError('bulkRole', __('office.groups.errors.role_invalid'));

            return;
        }

        $count = $action->handle($this->user(), $group, $this->selected, $role, $this->bulkStaffId, $this->bulkCustomerId, request()->ip());

        $this->reset('selected', 'bulkRole', 'bulkStaffId', 'bulkCustomerId', 'customerSearch');
        $this->dispatch('toast', title: __('admin.toast.saved'), body: trans_choice('office.groups.show.roles_set', $count, ['count' => $count]), type: 'success');
    }

    public function forget(int $memberId, ForgetGroupMember $action): void
    {
        $group = $this->group();
        $this->authorize('mark', $group);

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.office.groups.show', $group)), navigate: false);

            return;
        }

        $counts = $action->handle($this->user(), $group, $memberId, request()->ip());

        $this->selected = array_values(array_diff(array_map('intval', $this->selected), [$memberId]));

        $remaining = TelegramGroupMember::query()->where('telegram_group_id', $group->id)->count();

        if ($this->getPage('members') > max(1, (int) ceil($remaining / self::MEMBERS_PER_PAGE))) {
            $this->resetPage('members');
        }
        $this->dispatch('toast', title: __('admin.toast.done'), body: __('office.groups.show.forgotten', ['messages' => $counts['messages']]), type: 'success');
    }

    /**
     * Untrack the group (password, deletes messages, members, analyses and threads; leads stay), then back to the list.
     */
    public function untrack(UntrackGroup $action): void
    {
        $group = $this->group();
        $this->authorize('mark', $group);

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.office.groups.show', $group)), navigate: false);

            return;
        }

        $action->handle($this->user(), $group, request()->ip());

        session()->flash('toast', ['title' => __('admin.toast.done'), 'body' => __('office.groups.untracked'), 'type' => 'success']);
        $this->redirectRoute('admin.office.groups', navigate: true);
    }

    public function render(): View
    {
        $group = $this->group()->loadMissing(['assignee:id,name', 'customer:id,name']);
        $user = $this->user();
        $canMark = $user->can('mark', $group);
        $canSeePhone = $user->can('office.mark') || ($group->assignee_id !== null && $group->assignee_id === $user->id);

        $members = TelegramGroupMember::query()
            ->where('telegram_group_id', $group->id)
            ->select(['id', 'telegram_group_id', 'telegram_id', 'role', 'is_bot', 'first_name', 'username', 'member_status', 'telegram_user_id', 'staff_user_id', 'customer_id', 'last_seen_at', 'message_count'])
            ->when($canSeePhone, fn ($query) => $query->addSelect('phone'))
            ->with(['staffUser:id,name', 'customer:id,name'])
            ->orderByDesc('message_count')
            ->orderBy('id')
            ->paginate(self::MEMBERS_PER_PAGE, pageName: 'members');

        $messages = TelegramGroupMessage::query()
            ->where('telegram_group_id', $group->id)
            ->with('member:id,first_name,username,role')
            ->orderByDesc('id')
            ->paginate(self::MESSAGES_PER_PAGE, pageName: 'messages');
        $messages->setCollection($messages->getCollection()->reverse()->values());

        $threads = TelegramGroupThread::query()
            ->where('telegram_group_id', $group->id)
            ->with(['lead', 'clientMember:id,first_name'])
            ->latest('updated_at')
            ->orderByDesc('id')
            ->limit(self::THREADS_SHOWN)
            ->get();

        // Plan 46: the feedback forms exist only for the threads on screen; any other key of the public array is dropped.
        $this->feedback = array_intersect_key($this->feedback, array_flip($threads->pluck('id')->all()));

        return view('livewire.admin.office.groups.show', [
            'group' => $group,
            'members' => $members,
            'messages' => $messages,
            'canMark' => $canMark,
            'canSeePhone' => $canSeePhone,
            'assignable' => $canMark ? OfficeStaff::assignable() : collect(),
            'candidates' => $canMark ? CustomerCandidates::search($this->customerSearch) : [],
            'customerName' => $canMark ? CustomerCandidates::name($this->bulkCustomerId) : null,
            'roles' => [GroupMemberRole::Employee, GroupMemberRole::Client, GroupMemberRole::Unknown],
            'threads' => $threads,
            'canAnalyse' => $user->can('analyse', $group),
            'analysable' => QueueGroupAnalysis::isAnalysable($group),
            'pending' => QueueGroupAnalysis::hasPending($group),
        ])->title($group->title ?? __('office.groups.untitled', ['id' => $group->id]));
    }

    /**
     * The group, read once per request (protected: never part of the Livewire snapshot).
     */
    protected ?TelegramGroup $loadedGroup = null;

    private function group(): TelegramGroup
    {
        return $this->loadedGroup ??= TelegramGroup::query()->findOrFail($this->groupId);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
