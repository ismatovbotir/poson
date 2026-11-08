<?php

namespace App\Livewire\Admin\Repairs;

use App\Actions\Repairs\AssignRepair;
use App\Enums\RepairCoverage;
use App\Enums\RepairStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Lead;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Repairs list (plan 24 B2): tabs Open · All · Closed, search (number, phone
 * digits, serial number), filters status, coverage and engineer (me /
 * unassigned / a user), whitelisted sort, a "Take" button for unassigned
 * repairs. Rows come from Repair::visibleTo() only, so the list matches the
 * direct URLs (RepairPolicy::view). No amounts are loaded or shown here.
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

    public const SEARCH_MAX = 100;

    public const FILTERS = ['search', 'status', 'coverage', 'engineer'];

    #[Url(except: 'open')]
    public string $tab = 'open';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $coverage = '';

    /**
     * '' (everyone visible) · 'me' · 'none' (unassigned) · a user id.
     */
    #[Url(except: '')]
    public string $engineer = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    public function mount(): void
    {
        $this->authorize('viewAny', Repair::class);
    }

    public function updated(string $property): void
    {
        // A status filter would fight the open / closed tab: show every tab's rows.
        if ($property === 'search') {
            $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        }

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

    public function take(int $id, AssignRepair $assignRepair): void
    {
        // Through the visible scope: another engineer's repair and a missing id look the same (404).
        $repair = Repair::query()->visibleTo($this->user())->findOrFail($id);
        $this->authorize('take', $repair);

        if ($this->runToastAction(fn () => $assignRepair->take($repair, $this->user()))) {
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('repairs.toast.taken', ['number' => $repair->number]));
        }
    }

    /**
     * Repair counts per tab (with the current search and filters).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function tabCounts(): array
    {
        $row = $this->filtered()->toBase()->selectRaw(
            'count(*) as total, coalesce(sum(case when repairs.status in ('.implode(',', array_fill(0, count(RepairStatus::open()), '?')).') then 1 else 0 end), 0) as open_total',
            RepairStatus::valuesOf(RepairStatus::open()),
        )->first();

        $total = (int) ($row->total ?? 0);
        $open = (int) ($row->open_total ?? 0);

        return ['open' => $open, 'all' => $total, 'closed' => $total - $open];
    }

    /**
     * People for the engineer filter: only for users who see other people's repairs.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function engineerOptions(): array
    {
        $user = $this->user();

        if (! $user->can('repairs.view-all')) {
            return [];
        }

        return User::query()
            ->whereIn('id', Repair::query()->visibleTo($user)->whereNotNull('assigned_to_id')->select('assigned_to_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
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

        if ($status = RepairStatus::tryFrom($this->status)) {
            $chips['status'] = __('repairs.fields.status').': '.$status->label();
        }

        if ($coverage = RepairCoverage::tryFrom($this->coverage)) {
            $chips['coverage'] = __('repairs.fields.coverage').': '.$coverage->label();
        }

        $engineerLabel = match (true) {
            $this->engineer === 'me' => __('repairs.filters.engineer_me'),
            $this->engineer === 'none' => __('repairs.filters.engineer_none'),
            ctype_digit($this->engineer) => $this->engineerOptions[(int) $this->engineer] ?? null,
            default => null,
        };

        if ($engineerLabel !== null) {
            $chips['engineer'] = __('repairs.fields.assigned_to').': '.$engineerLabel;
        }

        return $chips;
    }

    public function render(): View
    {
        $user = $this->user();
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'open';
        [$column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['newest'];

        $repairs = $this->filtered()
            ->when($tab === 'open', fn (Builder $query) => $query->open())
            ->when($tab === 'closed', fn (Builder $query) => $query->closed())
            ->select(['repairs.id', 'number', 'status', 'coverage', 'contact_name', 'contact_phone', 'company', 'device_type', 'device_brand', 'device_model', 'serial_number', 'assigned_to_id', 'created_at', 'updated_at', 'status_changed_at'])
            ->with('assignedTo:id,name')
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.repairs.index', [
            'items' => $repairs,
            'currentTab' => $tab,
            'filtering' => trim($this->search) !== '' || $this->activeFilters !== [],
            'canCreate' => $user->can('create', Repair::class),
            'takeable' => $repairs->getCollection()->filter(fn (Repair $repair): bool => $user->can('take', $repair))->modelKeys(),
            'tz' => Lead::TIMEZONE,
        ])->title(__('repairs.title'));
    }

    /**
     * Visible repairs with the search and filters (not the tab).
     *
     * @return Builder<Repair>
     */
    private function filtered(): Builder
    {
        $user = $this->user();

        return Repair::query()
            ->visibleTo($user)
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->search(mb_substr($this->search, 0, self::SEARCH_MAX)))
            ->when(RepairStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('repairs.status', $this->status))
            ->when(RepairCoverage::tryFrom($this->coverage) !== null, fn (Builder $query) => $query->where('repairs.coverage', $this->coverage))
            ->when($this->engineer === 'me', fn (Builder $query) => $query->where('repairs.assigned_to_id', $user->id))
            ->when($this->engineer === 'none', fn (Builder $query) => $query->whereNull('repairs.assigned_to_id'))
            ->when(ctype_digit($this->engineer), fn (Builder $query) => $query->where('repairs.assigned_to_id', (int) $this->engineer));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
