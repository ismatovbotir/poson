{{--
    The customer's order page /o/{ulid} (plan 11 v2 §1.10), PublicOrderController.
    Noindex, no site navigation, printable. Never the buyer's name, phone, company, tax id or address.
    Payment instructions only (no "I've paid" button: staff confirm against the bank statement).
--}}
@php
    /** @var \App\Models\Order $order */
    $legal = $company['legal'];
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
    $date = fn ($value) => \App\Support\DateFormat::iso($value, 'D MMMM YYYY');
    $method = $order->payment_method;
    $separate = $order->delivery_pricing === \App\Enums\DeliveryPricing::Separate;
    $purpose = __('orders.public.purpose', ['number' => $order->number]);
    $notice = session('order_notice');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('orders.public.title', ['number' => $order->number]) }} · {{ $company['name'] }}</title>
    @fonts
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none !important; } body { background: #fff !important; } }</style>
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto max-w-4xl px-4 py-6 sm:py-10">
        <div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex gap-1 text-sm" aria-label="{{ __('orders.public.language') }}">
                @foreach (config('app.supported_locales') as $lang)
                    <a href="{{ route('orders.public', ['ulid' => $order->ulid, 'lang' => $lang]) }}" lang="{{ $lang }}" aria-label="{{ __('admin.locale.'.$lang, [], $lang) }}" @if ($lang === $locale) aria-current="true" @endif @class(['rounded-md px-2.5 py-1 font-semibold', 'bg-slate-900 text-white' => $lang === $locale, 'text-slate-600 hover:bg-slate-200' => $lang !== $locale])>{{ strtoupper($lang) }}</a>
                @endforeach
            </nav>
            <div class="flex gap-2">
                <button type="button" onclick="window.print()" class="h-10 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold hover:bg-slate-100">{{ __('orders.public.print') }}</button>
                @if ($hasInvoice)
                    <a href="{{ route('orders.public.invoice', ['ulid' => $order->ulid, 'lang' => $locale]) }}" class="inline-flex h-10 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-700" data-download-invoice>{{ __('orders.public.download_invoice') }}</a>
                @endif
            </div>
        </div>

        @if ($notice === 'pay_unavailable')
            <p class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900" role="status" data-pay-unavailable>{{ __('orders.public.pay_unavailable') }}</p>
        @endif

        @if ($payable && $order->amount_updated_at !== null)
            <p class="mb-4 rounded-xl border border-sky-300 bg-sky-50 px-4 py-3 text-sm font-medium text-sky-900" role="status" data-amount-updated>{{ __('orders.public.amount_updated', ['total' => $money($order->total)]) }}</p>
        @endif

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
            <header class="flex flex-col gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:justify-between">
                <div>
                    @if ($logo)
                        <img src="{{ $logo }}" alt="{{ $company['name'] }}" class="max-h-14 max-w-48">
                    @else
                        <p class="text-xl font-extrabold">{{ $company['name'] }}</p>
                    @endif
                </div>
                <div class="text-sm text-slate-600 sm:text-right">
                    @if ($company['phone'])<p class="font-semibold text-slate-900">{{ $company['phone'] }}</p>@endif
                    @if ($company['email'])<p>{{ $company['email'] }}</p>@endif
                </div>
            </header>

            <h1 class="mt-5 text-2xl font-bold tracking-tight">{{ __('orders.public.title', ['number' => $order->number]) }}</h1>
            <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                <div><dt class="inline text-slate-500">{{ __('orders.public.date') }}:</dt> <dd class="inline">{{ $date($order->created_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE)) }}</dd></div>
                <div><dt class="inline text-slate-500">{{ __('orders.public.status') }}:</dt> <dd class="inline font-semibold" data-status>{{ $order->status->label() }}</dd></div>
                <div><dt class="inline text-slate-500">{{ __('orders.public.payment') }}:</dt> <dd class="inline font-semibold" data-payment-status>{{ $order->payment_status->label() }} · {{ $method->label() }}</dd></div>
                <div><dt class="inline text-slate-500">{{ __('orders.public.delivery') }}:</dt> <dd class="inline" data-delivery>{{ $order->delivery_type->label() }}@if ($order->region) · {{ $order->region->label() }}@endif</dd></div>
            </dl>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-3">{{ __('orders.invoice.item') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('orders.invoice.qty') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('orders.invoice.price') }}</th>
                            <th class="py-2 pl-3 text-right">{{ __('orders.invoice.total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="py-3 pr-3"><span class="font-medium">{{ $item->name }}</span>@if ($item->isRollLine())<span class="block text-sm text-slate-600" data-roll-line>{{ $item->rollDescription($locale, withRolls: false) }}</span>@endif @if ($item->sku)<span class="block font-mono text-xs text-slate-500">{{ $item->sku }}</span>@endif</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ \App\Support\NumberFormat::format($item->qty, $locale) }} {{ $item->unit?->label() }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ $money($item->price) }}</td>
                                <td class="whitespace-nowrap py-3 pl-3 text-right font-semibold tabular-nums">{{ $money($item->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <dl class="mt-4 ml-auto max-w-sm space-y-1.5 border-t border-slate-200 pt-4 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">{{ __('orders.public.subtotal') }}</dt><dd class="tabular-nums">{{ $money($order->subtotal) }}</dd></div>
                <div class="flex justify-between gap-4" data-delivery-amount>
                    <dt class="text-slate-500">{{ $order->delivery_type === \App\Enums\DeliveryType::CourierKokand ? __('checkout.delivery.kokand_line') : __('orders.public.delivery') }}</dt>
                    {{-- A delivery already in the total is always shown (it may have been paid before the delivery changed). --}}
                    <dd class="text-right tabular-nums">{{ $separate && $order->delivery_amount === 0 ? __('orders.public.delivery_separate_short') : $money($order->delivery_amount) }}</dd>
                </div>
                <div class="flex justify-between text-lg font-bold"><dt>{{ __('orders.invoice.grand_total') }}</dt><dd class="tabular-nums" data-total>{{ $money($order->total) }}</dd></div>
                <div class="flex justify-between text-slate-500"><dt>{{ __('orders.invoice.vat', ['percent' => \App\Support\Vat::PERCENT]) }}</dt><dd class="tabular-nums">{{ $money($order->vat_amount) }}</dd></div>
            </dl>

            @if ($separate)
                <p class="mt-3 text-sm text-slate-600" data-delivery-separate>{{ $order->delivery_amount > 0 ? __('orders.public.delivery_additional_separate') : __('orders.public.delivery_separate') }}</p>
            @endif
        </article>

        {{-- How to pay the goods --}}
        <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8" aria-labelledby="pay-title" data-payment-instructions="{{ $method->value }}">
            <h2 id="pay-title" class="text-lg font-bold">{{ __('orders.public.how_to_pay') }}</h2>

            @if ($cancelled && $order->payment_status !== \App\Enums\PaymentStatus::Refunded)
                <p class="mt-2 text-sm text-slate-600" data-order-cancelled>{{ __('orders.public.cancelled') }}</p>
            @elseif ($order->payment_status === \App\Enums\PaymentStatus::Paid)
                <p class="mt-2 text-sm font-semibold text-emerald-700" data-paid>{{ __('orders.public.paid') }}</p>
            @elseif ($order->payment_status === \App\Enums\PaymentStatus::Refunded)
                <p class="mt-2 text-sm text-slate-600">{{ __('orders.public.refunded') }}</p>
            @elseif (! $payable)
                <p class="mt-2 text-sm text-slate-600">{{ __('orders.public.pending') }}</p>
            @elseif ($method === \App\Enums\PaymentMethod::UzqrStatic)
                @include('site.partials.order-uzqr', ['amount' => $order->total, 'purpose' => $purpose])
            @elseif ($method === \App\Enums\PaymentMethod::BankTransfer)
                @include('site.partials.order-bank', ['amount' => $order->total, 'purpose' => $purpose])
            @elseif ($method->isOnline())
                @if ($canPayOnline)
                    <form method="POST" action="{{ route('orders.public.pay', ['ulid' => $order->ulid, 'lang' => $locale]) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="inline-flex h-11 items-center rounded-lg bg-brand-600 px-6 text-sm font-semibold text-white hover:bg-brand-700" data-pay-button>{{ __('orders.public.pay_with', ['method' => $method->label()]) }}</button>
                    </form>
                @else
                    <p class="mt-2 text-sm text-slate-600" data-online-unavailable>{{ __('orders.public.online_unavailable') }}</p>
                @endif
            @else
                <p class="mt-2 text-sm text-slate-600" data-on-receipt>{{ __('orders.public.on_receipt') }}</p>
            @endif

            @if ($payable && ($method === \App\Enums\PaymentMethod::UzqrStatic || $method === \App\Enums\PaymentMethod::BankTransfer))
                <p class="mt-4 text-sm text-slate-600" data-confirm-note>{{ __('orders.public.confirm_note') }}</p>
            @endif
        </section>

        {{-- Delivery billed separately: an outstanding charge (security condition 23) --}}
        @if ($charge !== null)
            <section class="mt-5 rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-8" aria-labelledby="charge-title" data-delivery-charge>
                <h2 id="charge-title" class="text-lg font-bold">{{ __('orders.public.charge_title') }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ __('orders.public.charge_text', ['amount' => $money($charge->amount)]) }}</p>
                @if ($uzqr)
                    @include('site.partials.order-uzqr', ['amount' => $charge->amount, 'purpose' => __('orders.public.charge_purpose', ['number' => $order->number])])
                @endif
                @if ($requisites)
                    @include('site.partials.order-bank', ['amount' => $charge->amount, 'purpose' => __('orders.public.charge_purpose', ['number' => $order->number])])
                @endif
            </section>
        @endif
    </main>
</body>
</html>
