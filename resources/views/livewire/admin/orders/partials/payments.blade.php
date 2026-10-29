{{--
    Payments panel of the order page (Admin, sales manager; never the service engineer).
    Manual mark-paid needs the exact amount, the paid-on date and (UzQR / bank) a unique reference.
--}}
@php
    $statusLabel = match ($order->payment_status) {
        \App\Enums\PaymentStatus::Paid => 'text-brand-700',
        \App\Enums\PaymentStatus::Refunded => 'text-slate-500',
        default => 'text-amber-700',
    };
@endphp
<section class="rounded-2xl border border-slate-200 bg-white" data-payments-panel>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3.5 sm:px-6">
        <h2 class="text-base font-semibold">{{ __('orders.payments.title') }} <span class="font-normal text-slate-500">· {{ $order->payment_method->label() }}</span></h2>
        <span class="text-sm font-semibold {{ $statusLabel }}">{{ $order->payment_status->label() }}</span>
    </div>

    @if ($goodsPayable && $canMarkPaid)
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-6">
            <p class="text-sm text-slate-600">{{ __('orders.payments.outstanding', ['amount' => $money($order->total)]) }}</p>
            <x-admin.button size="sm" icon="check" wire:click="startPayment" data-action="mark-paid">{{ __('orders.actions.mark_paid') }}</x-admin.button>
        </div>
    @elseif ($order->payment_status === \App\Enums\PaymentStatus::Unpaid && $order->payment_method->isOnline())
        <p class="border-b border-slate-100 px-4 py-3.5 text-sm text-slate-600 sm:px-6">{{ __('orders.payments.online_only') }}</p>
    @endif

    <ul class="divide-y divide-slate-100" data-payment-rows>
        @forelse ($order->payments as $payment)
            <li wire:key="payment-{{ $payment->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3.5 sm:px-6" data-payment="{{ $payment->id }}">
                <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600"><x-admin.icon :name="$payment->purpose === \App\Enums\OrderPaymentPurpose::Delivery ? 'truck' : 'card'" size="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">
                        {{ $payment->purpose->label() }}@if ($payment->method) · {{ $payment->method->label() }}@endif @if ($payment->receipt_channel) · {{ $payment->receipt_channel->label() }}@endif
                        <x-admin.badge :color="$payment->status->color()" class="ml-1">{{ $payment->status->label() }}</x-admin.badge>
                        @if ($payment->reconciled_at)<x-admin.badge color="outline" class="ml-1">{{ __('orders.payments.reconciled') }}</x-admin.badge>@endif
                    </p>
                    <p class="truncate text-xs text-slate-500">
                        @if ($payment->reference)<span class="font-mono" data-reference>{{ $payment->reference }}</span> · @endif
                        @if ($payment->paid_on){{ $payment->paid_on->format('d.m.Y') }} · @endif
                        {{ $payment->recordedBy?->name ?? '' }}
                        @if ($payment->cancel_reason) · {{ $payment->cancel_reason }}@endif
                        @if ($payment->refund_reason) · {{ $payment->refund_reason }}@endif
                    </p>
                </div>
                <p @class(['text-sm font-semibold tabular-nums', 'line-through text-slate-400' => $payment->status === \App\Enums\OrderPaymentStatus::Cancelled])>{{ $money($payment->amount) }}</p>
                <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                    @if ($payment->status === \App\Enums\OrderPaymentStatus::Pending)
                        @if ($canMarkPaid && ! $order->status->isClosed())
                            <x-admin.button size="sm" variant="secondary" wire:click="startPayment({{ $payment->id }})" data-action="charge-paid">{{ __('orders.actions.mark_paid') }}</x-admin.button>
                        @endif
                        @if ($canEdit)
                            <x-admin.button size="sm" variant="ghost" x-on:click="reasonTitle = @js(__('orders.charge_cancel_title')); reasonMethod = 'cancelDeliveryCharge'; reasonArg = {{ $payment->id }}; reasonOpen = true" data-action="charge-cancel">{{ __('orders.actions.cancel_charge') }}</x-admin.button>
                        @endif
                    @elseif ($payment->status === \App\Enums\OrderPaymentStatus::Paid)
                        @if ($canVoid && $payment->isManual() && ! $order->status->isShippedOrLater())
                            <x-admin.button size="sm" variant="ghost" x-on:click="reasonTitle = @js(__('orders.void_title')); reasonMethod = 'voidPayment'; reasonArg = {{ $payment->id }}; reasonOpen = true" data-action="void">{{ __('orders.actions.void') }}</x-admin.button>
                        @endif
                        @if ($canRefund && $payment->method !== \App\Enums\PaymentMethod::Payme && ($payment->purpose === \App\Enums\OrderPaymentPurpose::Delivery || $order->isRefundable()))
                            <x-admin.button size="sm" variant="ghost" icon="refund" x-on:click="reasonTitle = @js(__('orders.refund_title', ['amount' => $money($payment->amount)])); reasonMethod = 'refundPayment'; reasonArg = {{ $payment->id }}; reasonOpen = true" data-action="refund">{{ __('orders.actions.refund') }}</x-admin.button>
                        @endif
                    @endif
                </div>
            </li>
        @empty
            <li class="px-4 py-3.5 text-sm text-slate-500 sm:px-6">{{ __('orders.payments.none') }}</li>
        @endforelse
    </ul>

    {{-- Delivery billed separately (condition 23) --}}
    @if ($canEdit && $order->delivery_pricing === \App\Enums\DeliveryPricing::Separate && ! $order->status->isClosed() && $pendingCharge === null)
        <form wire:submit="addDeliveryCharge" class="flex flex-wrap items-end gap-3 border-t border-slate-100 px-4 py-3.5 sm:px-6" data-add-charge>
            <x-admin.input class="w-44" wire:model="chargeAmount" name="charge.amount" inputmode="numeric" :label="__('orders.payments.charge_amount')" maxlength="20" />
            <x-admin.input class="min-w-0 flex-1" wire:model="chargeNote" name="charge.note" :label="__('orders.payments.charge_note')" maxlength="200" />
            <x-admin.button type="submit" size="sm" icon="plus" loading="addDeliveryCharge">{{ __('orders.actions.add_charge') }}</x-admin.button>
        </form>
    @endif

    @if ($order->is_test === false && $order->payment_status === \App\Enums\PaymentStatus::Unpaid && $canEdit && \App\Actions\Orders\GenerateOrderInvoicePdf::hasInvoice($order))
        <div class="border-t border-slate-100 px-4 py-3 sm:px-6">
            <button type="button" wire:click="reissueInvoice" class="text-sm font-semibold text-brand-600 hover:text-brand-700" data-action="reissue">{{ __('orders.actions.reissue', ['number' => $order->invoiceNumber()]) }}</button>
        </div>
    @endif
</section>

{{-- Mark paid dialog --}}
<div x-cloak x-show="payOpen" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="pay-title" x-on:keydown.escape.window="payOpen = false">
    <div class="absolute inset-0 bg-slate-900/50" x-on:click="payOpen = false"></div>
    <form wire:submit="markPaid" x-trap.noscroll="payOpen" class="relative w-full rounded-t-2xl bg-white p-5 shadow-2xl sm:max-w-lg sm:rounded-2xl" data-pay-form>
        <h2 id="pay-title" class="text-base font-semibold">{{ $payChargeId === null ? __('orders.pay.title_goods', ['number' => $order->number]) : __('orders.pay.title_charge') }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ __('orders.pay.hint') }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @if ($payChargeId !== null)
                <x-admin.select class="sm:col-span-2" wire:model.live="payMethod" name="pay.method" :label="__('orders.fields.payment_method')" :options="collect($payMethods)->mapWithKeys(fn ($method) => [$method->value => $method->label()])->all()" />
            @endif
            <x-admin.input wire:model="payAmount" name="pay.amount" inputmode="numeric" :label="__('orders.pay.amount')" :hint="__('orders.pay.amount_hint')" maxlength="20" />
            <x-admin.input wire:model="payOn" name="pay.paid_on" type="date" :label="__('orders.payments.paid_on')" />
            @php($method = $payChargeId === null ? $order->payment_method : \App\Enums\PaymentMethod::tryFrom($payMethod))
            @if ($method === \App\Enums\PaymentMethod::OnReceipt)
                <x-admin.select class="sm:col-span-2" wire:model="payChannel" name="pay.receipt_channel" :label="__('orders.pay.channel')" :placeholder="__('orders.pay.channel_placeholder')" :options="collect(\App\Enums\ReceiptChannel::cases())->mapWithKeys(fn ($channel) => [$channel->value => $channel->label()])->all()" />
            @endif
            <x-admin.input class="sm:col-span-2" wire:model="payReference" name="pay.reference" :label="__('orders.payments.reference')" :hint="$method?->requiresReference() ? __('orders.pay.reference_required') : __('orders.pay.reference_optional')" maxlength="100" />
            <x-admin.input class="sm:col-span-2" wire:model="payNote" name="pay.note" :label="__('orders.pay.note')" maxlength="200" />
        </div>
        @error('pay.method')<p class="mt-3 text-sm text-danger">{{ $message }}</p>@enderror
        @error('pay.payment')<p class="mt-3 text-sm text-danger">{{ $message }}</p>@enderror
        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" x-on:click="payOpen = false" class="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('admin.confirm.cancel') }}</button>
            <x-admin.button type="submit" icon="check" loading="markPaid" data-pay-submit>{{ __('orders.actions.mark_paid') }}</x-admin.button>
        </div>
    </form>
</div>
