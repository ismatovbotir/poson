<?php

namespace App\Livewire\Admin\Office\Chats;

use App\Actions\Office\OfficeStaff;
use App\Enums\AgentRole;
use App\Enums\AnalysisStatus;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Virtual office → Business chats (/admin/office/chats, plan 41 §8.2,
 * office.chats): client chats from BusinessChat::visibleTo() only (Admin
 * every client chat, staff only chats assigned to them, VO-9), so the list
 * matches the chat URLs. Filters (allow-listed) and the shown analysis both
 * use the latest DONE analysis (role, draft, needs phone); assignee (Admin only), lead (open /
 * none). No Telegram id, phone or message text is selected.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithAdminPagination;

    public const PER_PAGE = 25;

    public const LEADS = ['', 'open', 'none'];

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $assignee = '';

    #[Url(except: false)]
    public bool $hasDraft = false;

    #[Url(except: false)]
    public bool $needsPhone = false;

    #[Url(except: '')]
    public string $lead = '';

    public function mount(): void
    {
        $this->authorize('viewAny', BusinessChat::class);
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', BusinessChat::class);
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('role', 'assignee', 'hasDraft', 'needsPhone', 'lead');
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $role = AgentRole::tryFrom($this->role);
        $role = $role !== null && in_array($role, AgentRole::routable(), true) ? $role : null;
        $seesAll = $user->can('office.chats-all');
        $assignee = $seesAll ? filter_var($this->assignee, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $unassigned = $seesAll && $this->assignee === 'none';
        $lead = in_array($this->lead, self::LEADS, true) ? $this->lead : '';
        $latest = fn (QueryBuilder $query) => $query->selectRaw('max(id)')->from('business_chat_analyses')->where('status', AnalysisStatus::Done->value)->groupBy('business_chat_id');
        $onLatest = fn (callable $where) => fn (Builder $query) => $query->whereHas('analyses', fn (Builder $analysis) => $where($analysis->whereIn('id', $latest)));

        $chats = BusinessChat::query()
            ->visibleTo($user)
            ->select(['id', 'first_name', 'username', 'kind', 'assignee_id', 'lead_id', 'unread_count', 'last_inbound_at', 'last_message_at'])
            ->with(['assignee:id,name,is_active', 'lead:id,status'])
            ->when($role !== null, $onLatest(fn (Builder $query) => $query->where('role', $role?->value)))
            ->when($this->hasDraft, $onLatest(fn (Builder $query) => $query->whereNotNull('draft_reply')))
            ->when($this->needsPhone, $onLatest(fn (Builder $query) => $query->where('lead_action', BusinessChatAnalysis::LEAD_NEEDS_PHONE)))
            ->when($assignee !== false, fn (Builder $query) => $query->where('business_chats.assignee_id', $assignee))
            ->when($unassigned, fn (Builder $query) => $query->whereNull('business_chats.assignee_id'))
            ->when($lead === 'open', fn (Builder $query) => $query->whereHas('lead', fn (Builder $inner) => $inner->open()))
            ->when($lead === 'none', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereNull('business_chats.lead_id')->orWhereDoesntHave('lead', fn (Builder $leadQuery) => $leadQuery->open())))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        // The latest DONE analysis of each chat on this page: the same rule as the filters.
        $pageIds = $chats->getCollection()->pluck('id')->all();
        $latestAnalyses = $pageIds === [] ? collect() : BusinessChatAnalysis::query()
            ->select(['id', 'business_chat_id', 'status', 'role', 'topic', 'draft_blocked', 'lead_action', 'created_at'])
            ->whereIn('id', fn (QueryBuilder $sub) => $sub->selectRaw('max(id)')->from('business_chat_analyses')
                ->where('status', AnalysisStatus::Done->value)->whereIn('business_chat_id', $pageIds)->groupBy('business_chat_id'))
            ->get()
            ->keyBy('business_chat_id');

        return view('livewire.admin.office.chats.index', [
            'chats' => $chats,
            'latestAnalyses' => $latestAnalyses,
            'seesAll' => $seesAll,
            'assignable' => $seesAll ? OfficeStaff::assignable() : collect(),
            'filtering' => $role !== null || $this->assignee !== '' || $this->hasDraft || $this->needsPhone || $lead !== '',
        ])->title(__('office.chats.title'));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
