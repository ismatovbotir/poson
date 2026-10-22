<?php

namespace App\Livewire\Admin\Customers;

use App\Enums\CustomerType;
use App\Enums\PriceLevel;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Customers list (plan 05b T5): search (name, email, INN, customer or
 * contact phone digits), filters (manager: me / unassigned / a user, type,
 * price level), whitelisted sort, table from lg and cards below. Rows come
 * from Customer::visibleTo().
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithAdminPagination;

    /**
     * Sort key → [column, direction].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SORTS = [
        'name' => ['name', 'asc'],
        'newest' => ['created_at', 'desc'],
        'updated' => ['updated_at', 'desc'],
    ];

    public const PER_PAGE = 25;

    public const FILTERS = ['search', 'manager', 'type', 'priceLevel'];

    #[Url(except: '')]
    public string $search = '';

    /**
     * '' · 'me' · 'none' · a user id.
     */
    #[Url(except: '')]
    public string $manager = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(as: 'price', except: '')]
    public string $priceLevel = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['sort', ...self::FILTERS], true)) {
            $this->resetPage();
        }
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

    /**
     * Managers that have customers: id => name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function managerOptions(): array
    {
        return User::query()
            ->whereIn('id', Customer::query()->whereNotNull('manager_id')->select('manager_id'))
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

        $managerLabel = match (true) {
            $this->manager === 'me' => __('crm.customers.filters.manager_me'),
            $this->manager === 'none' => __('crm.customers.filters.manager_none'),
            ctype_digit($this->manager) => $this->managerOptions[(int) $this->manager] ?? null,
            default => null,
        };

        if ($managerLabel !== null) {
            $chips['manager'] = __('crm.customers.fields.manager').': '.$managerLabel;
        }

        if ($type = CustomerType::tryFrom($this->type)) {
            $chips['type'] = __('crm.customers.fields.type').': '.$type->label();
        }

        if ($level = PriceLevel::tryFrom($this->priceLevel)) {
            $chips['priceLevel'] = __('crm.customers.fields.price_level').': '.$level->label();
        }

        return $chips;
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        [$column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['name'];

        $customers = Customer::query()
            ->visibleTo($user)
            ->search($this->search)
            ->when($this->manager === 'me', fn (Builder $query) => $query->where('manager_id', $user->id))
            ->when($this->manager === 'none', fn (Builder $query) => $query->whereNull('manager_id'))
            ->when(ctype_digit($this->manager), fn (Builder $query) => $query->where('manager_id', (int) $this->manager))
            ->when(CustomerType::tryFrom($this->type) !== null, fn (Builder $query) => $query->where('type', $this->type))
            ->when(PriceLevel::tryFrom($this->priceLevel) !== null, fn (Builder $query) => $query->where('price_level', $this->priceLevel))
            ->with(['manager:id,name', 'primaryContact'])
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.customers.index', [
            'items' => $customers,
            'filtering' => trim($this->search) !== '' || $this->activeFilters !== [],
            'canCreate' => $user->can('create', Customer::class),
        ])->title(__('crm.customers.title'));
    }
}
