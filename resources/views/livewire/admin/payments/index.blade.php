@php($money = fn (int $tiyin) => \App\Support\Money::format($tiyin))

{{-- Payments ledger (plan 11b). "Manually confirmed, not reconciled" + Admin reconcile tick (condition 21). --}}
<div>
    <x-admin.page-header :title="__('orders.payments.list_title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.sales') }} <span class="text-slate-300">/</span> {{ __('orders.payments.list_title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    @include('livewire.admin.payments.partials.uzqr-attention')

    <div class="mt-5 flex flex-wrap items-end gap-2">
        <label class="relative min-w-0 flex-1 basis-full sm:basis-auto">
            <span class="sr-only">{{ __('admin.common.search') }}</span>
            <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('orders.payments.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
        </label>
        <x-admin.select class="w-40" wire:model.live="status" :placeholder="__('orders.filters.all')" :options="collect(\App\Enums\OrderPaymentStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" :aria-label="__('orders.fields.status')" />
        <x-admin.select class="w-44" wire:model.live="method" :placeholder="__('orders.filters.all')" :options="collect(\App\Enums\PaymentMethod::available())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" :aria-label="__('orders.fields.payment_method')" />
        <x-admin.select class="w-40" wire:model.live="purpose" :placeholder="__('orders.filters.all')" :options="collect(\App\Enums\OrderPaymentPurpose::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" :aria-label="__('orders.payments.purpose')" />
        <x-admin.input class="w-40" wire:model.live="from" type="date" :aria-label="__('orders.filters.from')" />
        <x-admin.input class="w-40" wire:model.live="to" type="date" :aria-label="__('orders.filters.to')" />
        <label class="inline-flex h-10 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">
            <input type="checkbox" wire:model.live="unreconciled" class="size-4 accent-brand-600" data-filter-unreconciled>{{ __('orders.payments.unreconciled_filter') }}
        </label>
        @if ($filtering)
            <button type="button" wire:click="clearFilters" class="px-1 text-xs font-semibold text-brand-600 hover:text-brand-700">{{ __('orders.filters.clear') }}</button>
        @endif
    </div>

    <div class="relative mt-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-white/50" aria-hidden="true"></div>
        @if ($payments->isEmpty())
            <x-admin.empty-state icon="card" :title="__('orders.payments.empty_title')" :body="__('orders.payments.empty_body')" />
        @else
            <x-admin.table>
                <x-slot:caption>{{ __('orders.payments.list_title') }}</x-slot:caption>
                <x-slot:head>
                    <th class="py-3 pl-5 pr-4">{{ __('orders.payments.date') }}</th>
                    <th class="px-4 py-3">{{ __('orders.columns.number') }}</th>
                    <th class="px-4 py-3">{{ __('orders.payments.purpose') }}</th>
                    <th class="px-4 py-3">{{ __('orders.fields.payment_method') }}</th>
                    <th class="px-4 py-3">{{ __('orders.payments.reference') }}</th>
                    <th class="px-4 py-3">{{ __('orders.fields.status') }}</th>
                    <th class="whitespace-nowrap px-4 py-3 text-right">{{ __('orders.payments.amount') }}</th>
                    <th class="py-3 pr-5">{{ __('orders.payments.reconciled') }}</th>
                </x-slot:head>
                @foreach ($payments as $payment)
                    <tr wire:key="payment-row-{{ $payment->id }}" data-payment="{{ $payment->id }}">
                        <td class="whitespace-nowrap py-3 pl-5 pr-4 text-slate-600">{{ $payment->paid_on?->format('d.m.Y') ?? $payment->created_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE)->format('d.m.Y') }}<span class="block text-xs text-slate-400">{{ $payment->recordedBy?->name }}</span></td>
                        <td class="whitespace-nowrap px-4 py-3"><a href="{{ route('admin.orders.show', $payment->order_id) }}" wire:navigate class="font-mono font-semibold hover:text-brand-700">{{ $payment->order?->number }}</a>@if ($payment->is_test) <x-admin.badge color="red">TEST</x-admin.badge>@endif</td>
                        <td class="px-4 py-3">{{ $payment->purpose->label() }}</td>
                        <td class="px-4 py-3">{{ $payment->method?->label() ?? __('admin.common.empty_value') }}@if ($payment->receipt_channel)<span class="block text-xs text-slate-500">{{ $payment->receipt_channel->label() }}</span>@endif</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $payment->reference ?? $payment->provider_ref ?? __('admin.common.empty_value') }}</td>
                        <td class="px-4 py-3"><x-admin.badge :color="$payment->status->color()">{{ $payment->status->label() }}</x-admin.badge></td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ $money($payment->amount) }}</td>
                        <td class="py-3 pr-5">
                            @if ($payment->status === \App\Enums\OrderPaymentStatus::Paid && $payment->isReconcilable())
                                @if ($payment->reconciled_at)
                                    <span class="text-xs text-emerald-700" data-reconciled>{{ __('orders.payments.reconciled_by', ['name' => $payment->reconciledBy?->name ?? '—', 'date' => $payment->reconciled_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE)->format('d.m.Y')]) }}</span>
                                    @if ($canReconcile)<button type="button" wire:click="setReconciled({{ $payment->id }}, false)" class="block text-xs font-semibold text-slate-500 hover:text-slate-700">{{ __('orders.payments.unreconcile') }}</button>@endif
                                @elseif ($canReconcile)
                                    <x-admin.button size="sm" variant="secondary" wire:click="setReconciled({{ $payment->id }}, true)" data-action="reconcile">{{ __('orders.payments.reconcile') }}</x-admin.button>
                                @else
                                    <span class="text-xs text-amber-700">{{ __('orders.payments.not_reconciled') }}</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>

            <x-admin.mobile-list class="mt-0">
                @foreach ($payments as $payment)
                    <article wire:key="payment-card-{{ $payment->id }}" class="rounded-2xl border border-slate-200 bg-white p-3.5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('admin.orders.show', $payment->order_id) }}" wire:navigate class="font-mono text-sm font-semibold">{{ $payment->order?->number }}</a>
                                <p class="text-sm text-slate-600">{{ $payment->purpose->label() }} · {{ $payment->method?->label() }}</p>
                            </div>
                            <span class="text-sm font-bold tabular-nums">{{ $money($payment->amount) }}</span>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
                            <x-admin.badge :color="$payment->status->color()">{{ $payment->status->label() }}</x-admin.badge>
                            <span class="font-mono">{{ $payment->reference }}</span>
                        </div>
                    </article>
                @endforeach
            </x-admin.mobile-list>

            <div class="mt-4">{{ $payments->links() }}</div>
        @endif
    </div>
</div>
