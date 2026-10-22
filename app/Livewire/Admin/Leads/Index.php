<?php

namespace App\Livewire\Admin\Leads;

use App\Actions\Leads\AssignLead;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Murojaatlar list (plan 05 T3): tabs Open · All · Closed, filters (status,
 * type, source, manager: me / unassigned / a user, created between,
 * duplicates only), search (name, company, email, phone digits, "L-123"),
 * whitelisted sort, table from lg and cards below, a "Take" button for
 * unassigned leads.
 *
 * Rows come from Lead::visibleTo() only, so the list matches the direct
 * URLs (LeadPolicy::view). The "Servis soʻrovlari" menu entry opens this
 * screen with type=service_request.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    public const TABS = ['open', 'all', 'closed'];

    /**
     * Sort key → [column, direction].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'updated' => ['updated_at', 'desc'],
        'status_changed' => ['status_changed_at', 'desc'],
    ];

    public const PER_PAGE = 25;

    public const FILTERS = ['search', 'status', 'type', 'source', 'manager', 'from', 'to', 'duplicates'];

    #[Url(except: 'open')]
    public string $tab = 'open';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $source = '';

    /**
     * '' (everyone visible) · 'me' · 'none' (unassigned) · a user id.
     */
    #[Url(except: '')]
    public string $manager = '';

    /**
     * Created from / to (Y-m-d, Tashkent day).
     */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: false)]
    public bool $duplicates = false;

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);
    }

    public function updated(string $property): void
    {
        // A status filter would fight the open / closed tab: show every tab's rows.
        if ($property === 'status' && $this->status !== '') {
            $this->tab = 'all';
        }

        if (in_array($property, ['tab', 'sort', ...self::FILTERS], true)) {
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'open';
        $this->status = '';
        $this->resetPage();
    }

    public function clearFilter(string $filter): void
    {
        if (in_array($filter, self::FILTERS, true)) {
            $this->reset($filter);
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    public function take(int $id, AssignLead $assignLead): void
    {
        $lead = Lead::query()->findOrFail($id);
        $this->authorize('take', $lead);

        if ($this->runToastAction(fn () => $assignLead->take($lead, $this->user()))) {
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('crm.leads.toast.taken', ['reference' => $lead->reference()]));
        }
    }

    /**
     * Lead counts per tab (with the current search and filters).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function tabCounts(): array
    {
        return [
            'open' => $this->filtered()->open()->count(),
            'all' => $this->filtered()->count(),
            'closed' => $this->filtered()->closed()->count(),
        ];
    }

    /**
     * People for the manager filter: only for users who see other people's leads.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function managerOptions(): array
    {
        $user = $this->user();

        if (! $user->can('leads.view-all') && ! $user->can('leads.assign')) {
            return [];
        }

        return User::query()
            ->whereIn('id', Lead::query()->visibleTo($user)->whereNotNull('manager_id')->select('manager_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Types the user can see (filter options).
     *
     * @return list<LeadType>
     */
    #[Computed]
    public function typeOptions(): array
    {
        $user = $this->user();

        return array_values(array_filter(
            LeadType::cases(),
            fn (LeadType $type): bool => $user->can($type->workPermission()) || ($type->isService() && $user->can('leads.service-view')),
        ));
    }

    /**
     * Active filters for the chips: filter => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function activeFilters(): array
    {
        $chips = [];

        if ($status = LeadStatus::tryFrom($this->status)) {
            $chips['status'] = __('crm.leads.fields.status').': '.$status->label();
        }

        if ($type = LeadType::tryFrom($this->type)) {
            $chips['type'] = __('crm.leads.fields.type').': '.$type->label();
        }

        if ($source = LeadSource::tryFrom($this->source)) {
            $chips['source'] = __('crm.leads.fields.source').': '.$source->label();
        }

        $managerLabel = match (true) {
            $this->manager === 'me' => __('crm.leads.filters.manager_me'),
            $this->manager === 'none' => __('crm.leads.filters.manager_none'),
            ctype_digit($this->manager) => $this->managerOptions[(int) $this->manager] ?? null,
            default => null,
        };

        if ($managerLabel !== null) {
            $chips['manager'] = __('crm.leads.fields.manager').': '.$managerLabel;
        }

        if ($from = $this->date($this->from)) {
            $chips['from'] = __('crm.leads.filters.from').': '.$from->format('d.m.Y');
        }

        if ($to = $this->date($this->to)) {
            $chips['to'] = __('crm.leads.filters.to').': '.$to->format('d.m.Y');
        }

        if ($this->duplicates) {
            $chips['duplicates'] = __('crm.leads.filters.duplicates');
        }

        return $chips;
    }

    public function render(): View
    {
        $user = $this->user();
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'open';
        [$column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['newest'];

        $leads = $this->filtered()
            ->when($tab === 'open', fn (Builder $query) => $query->open())
            ->when($tab === 'closed', fn (Builder $query) => $query->closed())
            ->with(['manager:id,name', 'items'])
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.leads.index', [
            'items' => $leads,
            'currentTab' => $tab,
            'filtering' => trim($this->search) !== '' || $this->activeFilters !== [],
            'canCreate' => $user->can('create', Lead::class),
            'takeable' => $leads->getCollection()->filter(fn (Lead $lead): bool => $lead->manager_id === null && $user->can('take', $lead))->modelKeys(),
        ])->title(__('crm.leads.title'));
    }

    /**
     * Visible leads with the search and filters (not the tab).
     *
     * @return Builder<Lead>
     */
    private function filtered(): Builder
    {
        $user = $this->user();
        $from = $this->date($this->from);
        $to = $this->date($this->to);

        return Lead::query()
            ->visibleTo($user)
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->search($this->search))
            ->when(LeadStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(LeadType::tryFrom($this->type) !== null, fn (Builder $query) => $query->where('type', $this->type))
            ->when(LeadSource::tryFrom($this->source) !== null, fn (Builder $query) => $query->where('source', $this->source))
            ->when($this->manager === 'me', fn (Builder $query) => $query->where('manager_id', $user->id))
            ->when($this->manager === 'none', fn (Builder $query) => $query->whereNull('manager_id'))
            ->when(ctype_digit($this->manager), fn (Builder $query) => $query->where('manager_id', (int) $this->manager))
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from->startOfDay()->utc()))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to->endOfDay()->utc()))
            ->when($this->duplicates, fn (Builder $query) => $query->whereNotNull('duplicate_of_id'));
    }

    /**
     * A Y-m-d filter value as the start of that Tashkent day, or null.
     */
    private function date(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, Lead::TIMEZONE);
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
