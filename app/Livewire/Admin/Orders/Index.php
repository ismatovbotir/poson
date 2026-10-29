<?php

namespace App\Livewire\Admin\Orders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Lead;
use App\Models\Order;
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
 * Orders list (plan 11b): search by number, buyer, company, tax id or
 * phone; filters (status, payment status, payment method, source, created
 * between); whitelisted sort; table from lg and cards below. Rows come from
 * Order::visibleTo() and are real orders only; with payments.test (Admin)
 * the "TEST orders" switch lists the TEST orders instead.
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
        'total' => ['total', 'desc'],
    ];

    public const PER_PAGE = 25;

    public const FILTERS = ['search', 'status', 'payment', 'method', 'source', 'from', 'to', 'test'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $payment = '';

    #[Url(except: '')]
    public string $method = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: false)]
    public bool $test = false;

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    public function mount(): void
    {
        $this->authorize('viewAny', Order::class);
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
     * @return array<string, string>
     */
    #[Computed]
    public function activeFilters(): array
    {
        $chips = [];

        if ($status = OrderStatus::tryFrom($this->status)) {
            $chips['status'] = __('orders.fields.status').': '.$status->label();
        }

        if ($payment = PaymentStatus::tryFrom($this->payment)) {
            $chips['payment'] = __('orders.fields.payment_status').': '.$payment->label();
        }

        if ($method = PaymentMethod::tryFrom($this->method)) {
            $chips['method'] = __('orders.fields.payment_method').': '.$method->label();
        }

        if ($source = OrderSource::tryFrom($this->source)) {
            $chips['source'] = __('orders.fields.source').': '.$source->label();
        }

        if ($from = $this->date($this->from)) {
            $chips['from'] = __('orders.filters.from').': '.$from->format('d.m.Y');
        }

        if ($to = $this->date($this->to)) {
            $chips['to'] = __('orders.filters.to').': '.$to->format('d.m.Y');
        }

        if ($this->showsTest()) {
            $chips['test'] = __('orders.filters.test');
        }

        return $chips;
    }

    public function render(): View
    {
        $user = $this->user();
        [$column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['newest'];
        $from = $this->date($this->from);
        $to = $this->date($this->to);

        $orders = Order::query()
            ->visibleTo($user)
            ->where('orders.is_test', $this->showsTest())
            ->search($this->search)
            ->when(OrderStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(PaymentStatus::tryFrom($this->payment) !== null, fn (Builder $query) => $query->where('payment_status', $this->payment))
            ->when(PaymentMethod::tryFrom($this->method) !== null, fn (Builder $query) => $query->where('payment_method', $this->method))
            ->when(OrderSource::tryFrom($this->source) !== null, fn (Builder $query) => $query->where('source', $this->source))
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from->startOfDay()->utc()))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to->endOfDay()->utc()))
            ->withCount('items')
            ->orderBy($column, $direction)
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.orders.index', [
            'items' => $orders,
            'filtering' => trim($this->search) !== '' || $this->activeFilters !== [],
            'canCreate' => $user->can('create', Order::class),
            'canSeeTest' => $user->can('payments.test'),
        ])->title(__('orders.title'));
    }

    private function showsTest(): bool
    {
        return $this->test && $this->user()->can('payments.test');
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
