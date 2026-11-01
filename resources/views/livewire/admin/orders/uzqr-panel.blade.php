@php
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
    $when = fn ($value) => $value?->copy()->setTimezone(\App\Models\Lead::TIMEZONE)->format('d.m.Y H:i');
@endphp

{{-- Dynamic UzQR sub-panel (plan 11e). Card type + last 4 only; never payer phone or the QR for real orders. --}}
<section class="mt-4 rounded-2xl border border-slate-200 bg-white" data-uzqr-panel>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3.5 sm:px-6">
        <h2 class="text-base font-semibold">{{ __('uzqr.admin.title') }}</h2>
        <div class="flex flex-wrap gap-2">
            @if ($canCheck && ($open || $checkable))
                <x-admin.button size="sm" variant="secondary" icon="restore" wire:click="checkStatus" loading="checkStatus" data-action="uzqr-check">{{ __('uzqr.admin.check_now') }}</x-admin.button>
            @endif
            @if ($canCancel && $open && $open->invoice_id)
                <x-admin.button size="sm" variant="secondary" x-on:click="$dispatch('confirm', { title: @js(__('uzqr.admin.cancel_qr')), body: @js(__('uzqr.admin.cancel_confirm')), label: @js(__('uzqr.admin.cancel_qr')), component: $wire.$id, method: 'cancelQr' })" data-action="uzqr-cancel">{{ __('uzqr.admin.cancel_qr') }}</x-admin.button>
            @endif
            @if ($canTestPay && ! $open && $order->payment_method === \App\Enums\PaymentMethod::UzqrDynamic)
                <x-admin.button size="sm" icon="card" wire:click="testPay" loading="testPay" data-action="uzqr-test-pay">{{ __('uzqr.admin.test_pay') }}</x-admin.button>
            @endif
        </div>
    </div>

    @if ($qr)
        <div class="flex flex-col items-center gap-2 border-b border-slate-100 px-4 py-4 sm:px-6" data-uzqr-test-qr>
            <img src="{{ $qr }}" alt="{{ __('uzqr.qr_alt') }}" width="200" height="200" class="size-48 rounded-xl border border-slate-200 bg-white p-2">
            <p class="text-xs text-slate-500">TEST · {{ $money($open->amount) }}</p>
        </div>
    @endif

    @if ($transactions->isEmpty())
        <p class="px-4 py-3.5 text-sm text-slate-500 sm:px-6">{{ __('uzqr.admin.none') }}</p>
    @else
        <ul class="divide-y divide-slate-100 text-sm">
            @foreach ($transactions as $row)
                <li wire:key="uzqr-row-{{ $row->id }}" class="px-4 py-3 sm:px-6" data-uzqr-row="{{ $row->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-mono text-xs">{{ $row->external_id }} @if ($row->mode === \App\Enums\PaymentMode::Test)<x-admin.badge color="violet">TEST</x-admin.badge>@endif</span>
                        <x-admin.badge :color="$row->status->color()">{{ $row->status->label() }}</x-admin.badge>
                    </div>
                    <p class="mt-1 text-slate-600">
                        {{ $money($row->amount) }} · {{ $when($row->created_at) }}
                        @if ($row->expires_at && $row->holdsOrder()) · {{ __('uzqr.admin.valid_until', ['time' => $when($row->expires_at)]) }}@endif
                        @if ($row->card_last4) · {{ __('uzqr.card_types.'.($row->card_type ?? 0)) }} ••{{ $row->card_last4 }}@endif
                        @if ($row->pay_id) · <span class="font-mono text-xs">{{ $row->pay_id }}</span>@endif
                    </p>
                    @if ($row->last_error)
                        <p class="mt-0.5 text-xs text-amber-700">{{ __('uzqr.admin.last_error', ['code' => $row->last_error.($row->last_error_code ? ' ('.$row->last_error_code.')' : '')]) }}</p>
                    @endif
                    @if ($row->settlement === \App\Enums\UzqrSettlement::Unmatched)
                        <p class="mt-1 text-xs font-semibold text-red-700" data-uzqr-unmatched>{{ __('uzqr.admin.unmatched', ['reason' => __('uzqr.unmatched_reasons.'.($row->unmatched_reason ?? 'unknown'))]) }}</p>
                        @if ($row->resolved_at)
                            <p class="text-xs text-slate-500">{{ __('uzqr.resolutions.'.$row->resolution) }} · {{ $when($row->resolved_at) }}</p>
                        @elseif ($canResolve && $resolvingId !== $row->id)
                            <x-admin.button size="sm" variant="secondary" class="mt-2" wire:click="startResolve({{ $row->id }})" data-action="uzqr-resolve">{{ __('uzqr.admin.resolve') }}</x-admin.button>
                        @endif
                    @endif
                    @if ($row->status === \App\Enums\UzqrTransactionStatus::Released && $row->resolved_at)
                        <p class="mt-1 text-xs text-slate-500" data-uzqr-released>{{ __('uzqr.admin.released_by', ['name' => $row->resolvedBy?->name ?? '—', 'time' => $when($row->resolved_at)]) }}</p>
                    @endif
                    @if ($canRelease && $releasingId !== $row->id && $row->isReleasable())
                        <x-admin.button size="sm" variant="secondary" class="mt-2" wire:click="startRelease({{ $row->id }})" data-action="uzqr-release">{{ __('uzqr.admin.release_hold') }}</x-admin.button>
                    @endif
                    @if ($releasingId === $row->id)
                        <form wire:submit="release" class="mt-3 grid gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3" data-uzqr-release-form>
                            <p class="text-sm font-semibold text-amber-900">{{ __('uzqr.admin.release_title') }}</p>
                            <p class="text-xs text-amber-900">{{ __('uzqr.admin.release_warning') }}</p>
                            <x-admin.input wire:model="releaseNote" :label="__('uzqr.admin.release_note')" maxlength="500" required />
                            @error('releaseNote')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                            <div class="flex justify-end gap-2">
                                <x-admin.button type="button" size="sm" variant="secondary" wire:click="cancelRelease">{{ __('admin.common.cancel') }}</x-admin.button>
                                <x-admin.button type="submit" size="sm" loading="release">{{ __('uzqr.admin.release_confirm') }}</x-admin.button>
                            </div>
                        </form>
                    @endif
                    @if ($resolvingId === $row->id)
                        <form wire:submit="resolve" class="mt-3 grid gap-3 rounded-xl border border-slate-200 p-3" data-uzqr-resolve-form>
                            <x-admin.select wire:model="resolution" :label="__('uzqr.admin.resolution')" :placeholder="__('uzqr.admin.choose')" :options="['applied' => __('uzqr.resolutions.applied'), 'refunded_externally' => __('uzqr.resolutions.refunded_externally')]" />
                            @error('resolution')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                            <x-admin.input wire:model="resolutionReference" :label="__('uzqr.admin.reference')" maxlength="100" />
                            @error('resolutionReference')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                            <x-admin.input wire:model="resolutionNote" :label="__('uzqr.admin.note')" maxlength="500" />
                            @error('resolutionNote')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                            <div class="flex justify-end gap-2">
                                <x-admin.button type="button" size="sm" variant="secondary" wire:click="cancelResolve">{{ __('admin.common.cancel') }}</x-admin.button>
                                <x-admin.button type="submit" size="sm" loading="resolve">{{ __('admin.common.save') }}</x-admin.button>
                            </div>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
