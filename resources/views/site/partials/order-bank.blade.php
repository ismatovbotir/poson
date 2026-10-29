{{-- Bank transfer block on /o: the seller's requisites, exact amount and purpose. --}}
<dl class="mt-3 grid gap-1.5 text-sm" data-bank-block>
    <div><dt class="inline text-slate-500">{{ __('orders.invoice.supplier') }}:</dt> <dd class="inline font-semibold">{{ $legal['legal_name'] ?: $company['name'] }}</dd></div>
    @if ($legal['inn'])<div><dt class="inline text-slate-500">{{ __('orders.invoice.inn') }}:</dt> <dd class="inline">{{ $legal['inn'] }}</dd></div>@endif
    @if ($legal['bank'])<div><dt class="inline text-slate-500">{{ __('orders.invoice.bank') }}:</dt> <dd class="inline">{{ $legal['bank'] }}</dd></div>@endif
    @if ($legal['mfo'])<div><dt class="inline text-slate-500">{{ __('orders.invoice.mfo') }}:</dt> <dd class="inline">{{ $legal['mfo'] }}</dd></div>@endif
    @if ($legal['account'])<div><dt class="inline text-slate-500">{{ __('orders.invoice.account') }}:</dt> <dd class="inline font-mono">{{ $legal['account'] }}</dd></div>@endif
    <div><dt class="inline text-slate-500">{{ __('orders.public.exact_amount') }}:</dt> <dd class="inline text-lg font-bold tabular-nums" data-exact-amount>{{ \App\Support\Money::format($amount) }}</dd></div>
    <div><dt class="inline text-slate-500">{{ __('orders.invoice.purpose_label') }}:</dt> <dd class="inline font-semibold" data-purpose>{{ $purpose }}</dd></div>
</dl>
