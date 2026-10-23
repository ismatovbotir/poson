<?php

namespace App\Livewire\Admin\Quotes;

use App\Enums\QuoteStatus;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quote;
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
 * Quotes list (plan 06): search by number or customer name, filters
 * (status, manager: me / a user, customer id, created between), whitelisted
 * sort, table from lg and cards below. Rows come from Quote::visibleTo().
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithAdminPagination;

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    public const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'valid_until' => ['valid_until', 'asc'],
        'total' => ['total', 'desc'],
    ];

    public const PER_PAGE = 25;

    public const FILTERS = ['search', 'status', 'manager', 'customer', 'from', 'to'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $manager = '';

    #[Url(except: '')]
    public string $customer = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    public function mount(): void
    {
        $this->authorize('viewAny', Quote::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['sort', ...self::FILTERS], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    public function clearFilter(string $filter): void
    {
        if (in_array($filter, self::FILTERS, true)) {
            $this->reset($filter);
            $this->resetPage();
        }
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function managerOptions(): array
    {
        return User::query()
            ->whereIn('id', Quote::query()->visibleTo($this->user())->whereNotNull('manager_id')->select('manager_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function activeFilters(): array
    {
        $chips = [];

        if ($status = QuoteStatus::tryFrom($this->status)) {
            $chips['status'] = __('crm.quotes.fields.status').': '.$status->label();
        }

        $manager = match (true) {
            $this->manager === 'me' => __('crm.quotes.filters.manager_me'),
            ctype_digit($this->manager) => $this->managerOptions[(int) $this->manager] ?? null,
            default => null,
        };

        if ($manager !== null) {
            $chips['manager'] = __('crm.quotes.fields.manager').': '.$manager;
        }

        if (ctype_digit($this->customer)) {
            $name = Customer::query()->whereKey((int) $this->customer)->value('name');

            if ($name !== null) {
                $chips['customer'] = __('crm.quotes.fields.customer').': '.$name;
            }
        }

        if ($from = $this->date($this->from)) {
            $chips['from'] = __('crm.quotes.filters.from').': '.$from->format('d.m.Y');
        }

        if ($to = $this->date($this->to)) {
            $chips['to'] = __('crm.quotes.filters.to').': '.$to->format('d.m.Y');
        }

        return $chips;
    }

    public function render(): View
    {
        $user = $this->user();
        [$column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['newest'];
        $from = $this->date($this->from);
        $to = $this->date($this->to);

        $quotes = Quote::query()
            ->visibleTo($user)
            ->search($this->search)
            ->when(QuoteStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->manager === 'me', fn (Builder $query) => $query->where('manager_id', $user->id))
            ->when(ctype_digit($this->manager), fn (Builder $query) => $query->where('manager_id', (int) $this->manager))
            ->when(ctype_digit($this->customer), fn (Builder $query) => $query->where('customer_id', (int) $this->customer))
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from->startOfDay()->utc()))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to->endOfDay()->utc()))
            ->with(['customer:id,name', 'manager:id,name'])
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.quotes.index', [
            'items' => $quotes,
            'filtering' => trim($this->search) !== '' || $this->activeFilters !== [],
            'canCreate' => $user->can('create', Quote::class),
        ])->title(__('crm.quotes.title'));
    }

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
