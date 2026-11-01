{{--
    "Pay with UzQR" on /o (plan 11e). Rendered from the DB only. The QR is an <img> data URI (never raw SVG);
    the poll attribute exists only while the invoice is pending (first 15 min). No payer data anywhere.
--}}
<div class="mt-3" data-uzqr-dynamic="{{ $state }}" @if ($poll) wire:poll.3s="refresh" @endif>
    @if ($state === 'paid')
        <p class="text-sm font-semibold text-emerald-700" data-uzqr-paid>{{ __('uzqr.site.paid') }}</p>
    @elseif ($state === 'received')
        <p class="text-sm font-semibold text-emerald-700" data-uzqr-received>{{ __('uzqr.site.received') }}</p>
    @elseif ($state === 'cancelled')
        <p class="text-sm text-slate-600">{{ __('orders.public.cancelled') }}</p>
    @else
        <p class="text-sm text-slate-600">{{ __('uzqr.site.intro') }}</p>
        <button type="button" wire:click="{{ in_array($state, ['start', 'creating'], true) ? 'open' : ($state === 'ended' ? 'newQr' : 'open') }}" wire:loading.attr="disabled" class="mt-3 inline-flex h-11 items-center rounded-lg bg-brand-600 px-6 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60" data-uzqr-open>
            {{ $state === 'ended' ? __('uzqr.site.new_qr') : __('uzqr.site.show_qr') }}
        </button>
    @endif

    @if ($show && ! in_array($state, ['paid', 'cancelled'], true))
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="uzqr-title" x-data x-on:keydown.escape.window="$wire.close()" data-uzqr-modal>
            <div class="max-h-[95vh] w-full max-w-md overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <h2 id="uzqr-title" class="text-lg font-bold">{{ __('uzqr.site.title') }}</h2>
                    <button type="button" wire:click="close" class="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('uzqr.site.close') }}">✕</button>
                </div>

                <dl class="mt-3 grid gap-1 text-sm">
                    <div><dt class="inline text-slate-500">{{ __('uzqr.site.order') }}:</dt> <dd class="inline font-semibold">{{ $order->number }}</dd></div>
                    <div><dt class="inline text-slate-500">{{ __('orders.public.exact_amount') }}:</dt> <dd class="inline text-lg font-bold tabular-nums" data-uzqr-amount>{{ \App\Support\Money::format($transaction?->amount ?? $order->total) }}</dd></div>
                </dl>

                @if ($notice)
                    <p class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status" data-uzqr-notice="{{ $notice }}">{{ __('uzqr.errors.'.$notice) }}</p>
                @endif

                @if ($state === 'pending' && $qrSvg)
                    <div class="mt-4 flex flex-col items-center gap-3">
                        <img src="{{ $qrSvg }}" alt="{{ __('uzqr.qr_alt') }}" width="256" height="256" class="size-64 rounded-xl border border-slate-200 bg-white p-2" data-uzqr-qr>
                        <p class="text-sm text-slate-600" x-data="{ left: {{ $secondsLeft }}, get text() { const h = Math.floor(this.left / 3600), m = Math.floor(this.left % 3600 / 60), s = this.left % 60; return (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); } }" x-init="setInterval(() => { if (left > 0) left-- }, 1000)">
                            {{ __('uzqr.site.valid_until', ['time' => $transaction->expires_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE)->format('d.m.Y H:i')]) }} · <span x-text="text" class="tabular-nums"></span>
                        </p>
                        <p class="flex items-center gap-2 text-sm font-medium text-sky-800" role="status" data-uzqr-waiting>
                            <span class="size-2 animate-pulse rounded-full bg-sky-500" aria-hidden="true"></span>{{ __('uzqr.site.waiting') }}
                        </p>
                        @if ($qrPng)
                            <a href="{{ $qrPng }}" download="poson-{{ $order->number }}-uzqr.png" class="inline-flex h-10 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-100" data-uzqr-png>{{ __('uzqr.site.save_png') }}</a>
                        @endif
                        <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600">
                            <li>{{ __('uzqr.site.step_scan') }}</li>
                            <li>{{ __('uzqr.site.step_same_phone') }}</li>
                            <li>{{ __('uzqr.site.step_confirm') }}</li>
                        </ol>
                        @unless ($poll)
                            <button type="button" wire:click="refresh" class="inline-flex h-10 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-700" data-uzqr-check>{{ __('uzqr.site.check_status') }}</button>
                        @endunless
                    </div>
                @elseif ($state === 'creating')
                    <p class="mt-4 text-sm text-slate-600" data-uzqr-creating>{{ __('uzqr.site.creating') }}</p>
                @elseif ($state === 'waiting')
                    <p class="mt-4 text-sm text-slate-600" data-uzqr-expired-waiting>{{ __('uzqr.site.expired_waiting') }}</p>
                    <button type="button" wire:click="refresh" class="mt-3 inline-flex h-10 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-700" data-uzqr-check>{{ __('uzqr.site.check_status') }}</button>
                @elseif ($state === 'ended')
                    <p class="mt-4 text-sm text-slate-600" data-uzqr-ended>{{ __('uzqr.site.ended') }}</p>
                    <button type="button" wire:click="newQr" wire:loading.attr="disabled" class="mt-3 inline-flex h-11 items-center rounded-lg bg-brand-600 px-6 text-sm font-semibold text-white hover:bg-brand-700" data-uzqr-new>{{ __('uzqr.site.new_qr') }}</button>
                @elseif ($state === 'received')
                    <p class="mt-4 text-sm font-semibold text-emerald-700">{{ __('uzqr.site.received') }}</p>
                @else
                    <button type="button" wire:click="open" wire:loading.attr="disabled" class="mt-4 inline-flex h-11 items-center rounded-lg bg-brand-600 px-6 text-sm font-semibold text-white hover:bg-brand-700" data-uzqr-create>{{ __('uzqr.site.show_qr') }}</button>
                @endif

                <p class="mt-4 text-xs text-slate-500">{{ __('uzqr.site.no_screenshots') }}</p>
            </div>
        </div>
    @endif
</div>
