{{-- Static UzQR payment block on /o: the code (data URI), recipient, exact amount and purpose. --}}
<div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start" data-uzqr-block>
    @if ($uzqr)
        <img src="{{ $uzqr }}" alt="{{ __('orders.public.uzqr_alt') }}" width="200" height="200" class="size-48 rounded-xl border border-slate-200 bg-white object-contain p-2" data-uzqr>
    @endif
    <dl class="grid gap-1.5 text-sm">
        @if ($uzqrRecipient)
            <div><dt class="inline text-slate-500">{{ __('orders.public.uzqr_recipient') }}:</dt> <dd class="inline font-semibold" data-uzqr-recipient>{{ $uzqrRecipient }}</dd></div>
        @endif
        <div><dt class="inline text-slate-500">{{ __('orders.public.exact_amount') }}:</dt> <dd class="inline text-lg font-bold tabular-nums" data-exact-amount>{{ \App\Support\Money::format($amount) }}</dd></div>
        <div><dt class="inline text-slate-500">{{ __('orders.invoice.purpose_label') }}:</dt> <dd class="inline font-semibold" data-purpose>{{ $purpose }}</dd></div>
        <p class="text-slate-600">{{ __('orders.public.uzqr_steps') }}</p>
    </dl>
</div>
