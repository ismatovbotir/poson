<?php

namespace App\Livewire\Admin\Payments;

use App\Actions\Orders\SetPaymentReconciled;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentMethod;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Lead;
use App\Models\OrderPayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Payments ledger (plan 11b, security condition 21): every order_payments
 * row (payments.viewAny: Admin, sales manager; TEST rows only with
 * payments.test), filters (status, method, purpose, paid between) and the
 * "manually confirmed, not reconciled" filter. Admins tick a paid manual
 * payment off against the bank statement (payments.reconcile, audited in
 * the order history).
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    public const PER_PAGE = 30;

    public const FILTERS = ['search', 'status', 'method', 'purpose', 'unreconciled', 'from', 'to'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $method = '';

    #[Url(except: '')]
    public string $purpose = '';

    #[Url(except: false)]
    public bool $unreconciled = false;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', OrderPayment::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    public function setReconciled(int $paymentId, bool $reconciled, SetPaymentReconciled $setReconciled): void
    {
        $payment = OrderPayment::query()->findOrFail($paymentId);

        $this->authorize('reconcile', $payment);

        if ($this->runToastAction(fn () => $setReconciled->handle($payment, $reconciled, $this->user()))) {
            $this->dispatch('toast', title: __('admin.toast.saved'), body: $reconciled ? __('orders.payments.toast_reconciled') : __('orders.payments.toast_unreconciled'), type: 'success');
        }
    }

    public function render(): View
    {
        $user = $this->user();
        $from = $this->date($this->from);
        $to = $this->date($this->to);
        $search = trim($this->search);

        $payments = OrderPayment::query()
            ->when(! $user->can('payments.test'), fn (Builder $query) => $query->where('order_payments.is_test', false))
            ->when(OrderPaymentStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(PaymentMethod::tryFrom($this->method) !== null, fn (Builder $query) => $query->where('method', $this->method))
            ->when(OrderPaymentPurpose::tryFrom($this->purpose) !== null, fn (Builder $query) => $query->where('purpose', $this->purpose))
            ->when($this->unreconciled, fn (Builder $query) => $query->unreconciled())
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from->startOfDay()->utc()))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<=', $to->endOfDay()->utc()))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('reference', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                ->orWhereHas('order', fn (Builder $order) => $order->where('number', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtoupper($search)).'%'))))
            ->with(['order:id,number,is_test', 'recordedBy:id,name', 'reconciledBy:id,name'])
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.payments.index', [
            'payments' => $payments,
            'canReconcile' => $user->can('payments.reconcile'),
            'filtering' => $search !== '' || $this->status !== '' || $this->method !== '' || $this->purpose !== '' || $this->unreconciled || $from !== null || $to !== null,
        ])->title(__('orders.payments.list_title'));
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
