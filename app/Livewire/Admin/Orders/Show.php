<?php

namespace App\Livewire\Admin\Orders;

use App\Actions\Orders\AddDeliveryCharge;
use App\Actions\Orders\CancelDeliveryCharge;
use App\Actions\Orders\ChangeOrderStatus;
use App\Actions\Orders\DeleteTestOrder;
use App\Actions\Orders\GenerateOrderInvoicePdf;
use App\Actions\Orders\MarkDeliveryChargePaid;
use App\Actions\Orders\MarkOrderPaid;
use App\Actions\Orders\RecordOrderRefund;
use App\Actions\Orders\ReissueInvoice;
use App\Actions\Orders\RenewOrderLink;
use App\Actions\Orders\UpdateOrderDelivery;
use App\Actions\Orders\VoidManualPayment;
use App\Data\Orders\ManualPaymentData;
use App\Data\Orders\OrderDeliveryData;
use App\Enums\DeliveryType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Order page (plan 11b, admin template order.html without the tier /
 * discount parts): status stepper and actions, lines with totals (VAT
 * included; staff-edited prices flagged), the payments panel (mark paid
 * with the exact amount, paid-on date and reference; delivery charges;
 * void / record refund for Admins), invoice downloads, the customer's
 * public link (copy / renew), buyer and delivery details and the history.
 *
 * Access is re-checked on every request; each write authorizes its own
 * ability (update, markPaid, voidPayment, refund, delete) and the action
 * re-checks the order state under its lock. The service engineer sees the
 * order read-only, without the payments panel, invoices or the public link
 * (security condition 30).
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    use HandlesActionErrors;

    public Order $order;

    /**
     * Mark-paid form: null = the goods total, else a pending delivery charge id.
     */
    public ?int $payChargeId = null;

    /**
     * Method of a delivery charge payment (the goods are paid with the order's method).
     */
    public string $payMethod = '';

    public string $payAmount = '';

    public string $payOn = '';

    public string $payReference = '';

    public string $payChannel = '';

    public string $payNote = '';

    public string $chargeAmount = '';

    public string $chargeNote = '';

    public string $reason = '';

    public function mount(Order $order): void
    {
        $this->authorize('view', $order);
        $this->order = $order;
        $this->payOn = now(Lead::TIMEZONE)->toDateString();
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->order);
    }

    public function changeStatus(string $status, ChangeOrderStatus $changeStatus): void
    {
        $this->authorize('update', $this->order);

        $to = OrderStatus::tryFrom($status);

        if ($to === null) {
            return;
        }

        if ($this->runToastAction(fn () => $changeStatus->handle($this->order, $to, $this->user(), $this->reason))) {
            $this->reset('reason');
            $this->refreshOrder();
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('orders.toast.status', ['status' => $to->label()]), type: 'success');
        }
    }

    /**
     * The Kokand courier address was checked on the confirmation call.
     */
    public function confirmKokandAddress(UpdateOrderDelivery $updateDelivery): void
    {
        $this->authorize('update', $this->order);

        if ($this->order->delivery_type !== DeliveryType::CourierKokand) {
            return;
        }

        if ($this->runToastAction(fn () => $updateDelivery->handle($this->order, new OrderDeliveryData(
            deliveryType: $this->order->delivery_type->value,
            address: (string) $this->order->address,
            kokandConfirmed: true,
        ), $this->user()))) {
            $this->refreshOrder();
            $this->dispatch('toast', title: __('admin.toast.saved'), body: __('orders.toast.kokand_confirmed'), type: 'success');
        }
    }

    /**
     * Open the mark-paid form for the goods (null) or a pending delivery charge.
     */
    public function startPayment(?int $chargeId = null): void
    {
        $this->authorize('markPaid', $this->order);

        $charge = $chargeId !== null ? $this->order->payments()->whereKey($chargeId)->first() : null;
        $this->payChargeId = $charge?->id;
        $this->payAmount = Money::tiyinToSums($charge?->amount ?? $this->order->total);
        $this->payOn = now(Lead::TIMEZONE)->toDateString();
        $this->payMethod = $this->order->payment_method->isManual() ? $this->order->payment_method->value : PaymentMethod::BankTransfer->value;
        $this->payChannel = '';
        $this->reset('payReference', 'payNote');
        $this->resetErrorBag();
        $this->dispatch('open-payment');
    }

    public function markPaid(MarkOrderPaid $markPaid, MarkDeliveryChargePaid $markChargePaid): void
    {
        $this->authorize('markPaid', $this->order);

        $data = new ManualPaymentData(
            method: $this->payChargeId === null ? $this->order->payment_method->value : $this->payMethod,
            amountReceived: Money::sumsToTiyin($this->payAmount),
            paidOn: $this->payOn,
            reference: $this->payReference,
            receiptChannel: $this->payChannel !== '' ? $this->payChannel : null,
            note: $this->payNote,
        );

        if ($this->payChargeId === null) {
            $this->runFormAction(fn () => $markPaid->handle($this->order, $data, $this->user()), 'pay.');
        } else {
            $charge = $this->order->payments()->whereKey($this->payChargeId)->firstOrFail();
            $this->runFormAction(fn () => $markChargePaid->handle($this->order, $charge, $data, $this->user()), 'pay.');
        }

        $this->reset('payReference', 'payNote', 'payChannel', 'payChargeId', 'payMethod');
        $this->refreshOrder();
        $this->dispatch('close-payment');
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('orders.toast.paid'), type: 'success');
    }

    public function addDeliveryCharge(AddDeliveryCharge $addCharge): void
    {
        $this->authorize('update', $this->order);

        $this->runFormAction(fn () => $addCharge->handle($this->order, Money::sumsToTiyin($this->chargeAmount), $this->user(), $this->chargeNote), 'charge.');

        $this->reset('chargeAmount', 'chargeNote');
        $this->refreshOrder();
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('orders.toast.charge_added'), type: 'success');
    }

    public function cancelDeliveryCharge(int $paymentId, CancelDeliveryCharge $cancelCharge): void
    {
        $this->authorize('update', $this->order);

        $charge = $this->order->payments()->whereKey($paymentId)->firstOrFail();

        if ($this->runToastAction(fn () => $cancelCharge->handle($this->order, $charge, $this->reason, $this->user()))) {
            $this->reset('reason');
            $this->refreshOrder();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('orders.toast.charge_cancelled'), type: 'success');
        }
    }

    public function voidPayment(int $paymentId, VoidManualPayment $void): void
    {
        $this->authorize('voidPayment', $this->order);

        $payment = $this->order->payments()->whereKey($paymentId)->firstOrFail();

        if ($this->runToastAction(fn () => $void->handle($this->order, $payment, $this->reason, $this->user()))) {
            $this->reset('reason');
            $this->refreshOrder();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('orders.toast.voided'), type: 'success');
        }
    }

    public function refundPayment(int $paymentId, RecordOrderRefund $refund): void
    {
        $this->authorize('refund', $this->order);

        $payment = $this->order->payments()->whereKey($paymentId)->firstOrFail();

        if ($this->runToastAction(fn () => $refund->handle($this->order, $payment, $this->reason, $this->user()))) {
            $this->reset('reason');
            $this->refreshOrder();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('orders.toast.refunded'), type: 'success');
        }
    }

    public function reissueInvoice(ReissueInvoice $reissue): void
    {
        $this->authorize('update', $this->order);

        if ($this->runToastAction(fn () => DB::transaction(function () use ($reissue): void {
            $order = Order::query()->whereKey($this->order->id)->lockForUpdate()->firstOrFail();
            $reissue->handle($order, $this->user());
        }))) {
            $this->refreshOrder();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('orders.toast.reissued', ['number' => $this->order->invoiceNumber()]), type: 'success');
        }
    }

    /**
     * The public link for the clipboard (Sales + Admin).
     */
    public function copyLink(): ?string
    {
        $this->authorize('viewPayments', $this->order);

        if (! $this->order->isPublicLinkValid()) {
            return null;
        }

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('orders.toast.link_copied'));

        return route('orders.public', ['ulid' => $this->order->ulid, 'lang' => $this->order->locale]);
    }

    public function renewLink(RenewOrderLink $renew): void
    {
        $this->authorize('update', $this->order);
        $this->authorize('viewPayments', $this->order);

        if ($this->runToastAction(fn () => $renew->handle($this->order, $this->user()))) {
            $this->refreshOrder();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('orders.toast.link_renewed'), type: 'success');
        }
    }

    public function delete(DeleteTestOrder $deleteOrder): void
    {
        $this->authorize('delete', $this->order);

        $number = $this->order->number;

        if ($this->runToastAction(fn () => $deleteOrder->handle($this->order))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $number, 'type' => 'success']);
            $this->redirectRoute('admin.orders.index', ['test' => 1], navigate: true);
        }
    }

    /**
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function activities(): Collection
    {
        return $this->order->activities()->with('user:id,name')->latest('created_at')->latest('id')->limit(200)->get();
    }

    public function render(): View
    {
        $user = $this->user();
        $this->order->loadMissing(['items.product', 'payments.recordedBy:id,name', 'customer:id,name,inn', 'manager:id,name', 'createdBy:id,name']);
        $canPayments = $user->can('viewPayments', $this->order);

        return view('livewire.admin.orders.show', [
            'canEdit' => $user->can('update', $this->order),
            'canPayments' => $canPayments,
            'canMarkPaid' => $user->can('markPaid', $this->order),
            'canVoid' => $user->can('voidPayment', $this->order),
            'canRefund' => $user->can('refund', $this->order),
            'canDelete' => $user->can('delete', $this->order),
            'canViewCustomer' => $this->order->customer !== null && $user->can('view', $this->order->customer),
            'canViewProducts' => $user->can('products.view'),
            'next' => array_values(array_filter($this->order->status->nextStatuses(), fn (OrderStatus $status): bool => $status !== OrderStatus::Cancelled)),
            'canCancel' => $this->order->status->canTransitionTo(OrderStatus::Cancelled),
            'hasInvoice' => $canPayments && GenerateOrderInvoicePdf::hasInvoice($this->order),
            'goodsPayable' => $this->order->payment_status === PaymentStatus::Unpaid && $this->order->payment_method->isManual() && $this->order->status !== OrderStatus::Cancelled,
            'publicUrl' => $canPayments && $this->order->isPublicLinkValid() ? route('orders.public', ['ulid' => $this->order->ulid, 'lang' => $this->order->locale]) : null,
            'payMethods' => PaymentMethod::manual(),
        ])->title(__('orders.show_title', ['number' => $this->order->number]));
    }

    private function refreshOrder(): void
    {
        $this->order->refresh();
        $this->order->unsetRelations();
        unset($this->activities);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
