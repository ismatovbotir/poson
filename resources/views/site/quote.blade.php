{{--
    The customer's quote page /q/{ulid} (plan 06), PublicQuoteController.
    Noindex, no site navigation, printable. Only the quote's own lines and totals.
--}}
@php
    /** @var \App\Models\Quote $quote */
    $customer = $quote->customer;
    $legal = $pdf['company']['legal'];
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
    $date = fn ($value) => \App\Support\DateFormat::iso($value, 'D MMMM YYYY');
    $expired = $quote->isExpired();
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('crm.quotes.pdf.title') }} {{ $quote->number }} · {{ $pdf['company']['name'] }}</title>
    @fonts
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none !important; } body { background: #fff !important; } }</style>
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <main class="mx-auto max-w-4xl px-4 py-6 sm:py-10">
        <div class="no-print mb-4 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex gap-1 text-sm" aria-label="{{ __('crm.quotes.public.language') }}">
                @foreach (config('app.supported_locales') as $lang)
                    <a href="{{ route('quotes.public', ['ulid' => $quote->ulid, 'lang' => $lang]) }}" lang="{{ $lang }}" aria-label="{{ __('admin.locale.'.$lang, [], $lang) }}" @if ($lang === $locale) aria-current="true" @endif @class(['rounded-md px-2.5 py-1 font-semibold', 'bg-slate-900 text-white' => $lang === $locale, 'text-slate-600 hover:bg-slate-200' => $lang !== $locale])>{{ strtoupper($lang) }}</a>
                @endforeach
            </nav>
            <div class="flex gap-2">
                <button type="button" onclick="window.print()" class="h-10 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold hover:bg-slate-100">{{ __('crm.quotes.public.print') }}</button>
                <a href="{{ route('quotes.public.pdf', ['ulid' => $quote->ulid, 'lang' => $locale]) }}" class="inline-flex h-10 items-center rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-700" data-download-pdf>{{ __('crm.quotes.public.download') }}</a>
            </div>
        </div>

        @if ($expired)
            <p class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900" role="status" data-expired>{{ __('crm.quotes.public.expired', ['date' => $date($quote->valid_until)]) }}</p>
        @endif

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8">
            <header class="flex flex-col gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:justify-between">
                <div>
                    @if ($pdf['logo'])
                        <img src="{{ $pdf['logo'] }}" alt="{{ $pdf['company']['name'] }}" class="max-h-14 max-w-48">
                    @else
                        <p class="text-xl font-extrabold">{{ $pdf['company']['name'] }}</p>
                    @endif
                </div>
                <div class="text-sm text-slate-600 sm:text-right">
                    <p class="font-semibold text-slate-900">{{ $legal['legal_name'] ?: $pdf['company']['name'] }}</p>
                    @if ($legal['inn'])<p>{{ __('crm.quotes.pdf.inn') }}: {{ $legal['inn'] }}</p>@endif
                    @if ($pdf['company']['phone'])<p>{{ $pdf['company']['phone'] }}</p>@endif
                    @if ($pdf['company']['email'])<p>{{ $pdf['company']['email'] }}</p>@endif
                </div>
            </header>

            <h1 class="mt-5 text-2xl font-bold tracking-tight">{{ __('crm.quotes.pdf.title') }} {{ $quote->number }}</h1>
            <dl class="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                <div><dt class="inline text-slate-500">{{ __('crm.quotes.pdf.date') }}:</dt> <dd class="inline">{{ $date($quote->created_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE)) }}</dd></div>
                <div><dt class="inline text-slate-500">{{ __('crm.quotes.pdf.valid_until') }}:</dt> <dd class="inline font-semibold">{{ $date($quote->valid_until) }}</dd></div>
                <div class="sm:col-span-2"><dt class="inline text-slate-500">{{ __('crm.quotes.pdf.customer') }}:</dt> <dd class="inline font-semibold">{{ $customer->name }}</dd></div>
            </dl>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-3">{{ __('crm.quotes.pdf.item') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('crm.quotes.pdf.qty') }}</th>
                            <th class="px-3 py-2">{{ __('crm.quotes.pdf.unit') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('crm.quotes.pdf.price') }}</th>
                            <th class="py-2 pl-3 text-right">{{ __('crm.quotes.pdf.total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($quote->items as $item)
                            <tr>
                                <td class="py-3 pr-3"><span class="font-medium">{{ $item->name }}</span>@if ($item->sku)<span class="block font-mono text-xs text-slate-500">{{ $item->sku }}</span>@endif</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ \App\Support\NumberFormat::format($item->qty, $locale) }}</td>
                                <td class="whitespace-nowrap px-3 py-3">{{ $item->unit?->label() }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ $money($item->price) }}</td>
                                <td class="whitespace-nowrap py-3 pl-3 text-right font-semibold tabular-nums">{{ $money($item->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <dl class="mt-4 ml-auto max-w-sm space-y-1.5 border-t border-slate-200 pt-4 text-sm">
                <div class="flex justify-between text-lg font-bold"><dt>{{ __('crm.quotes.pdf.grand_total') }}</dt><dd class="tabular-nums" data-total>{{ $money($quote->total) }}</dd></div>
                <div class="flex justify-between text-slate-500"><dt>{{ __('crm.quotes.pdf.vat', ['percent' => \App\Models\Quote::VAT_PERCENT]) }}</dt><dd class="tabular-nums">{{ $money($quote->vat_amount) }}</dd></div>
            </dl>

            @if (filled($quote->notes))
                <div class="mt-6 rounded-xl bg-slate-50 p-4 text-sm"><p class="font-semibold">{{ __('crm.quotes.pdf.notes') }}</p><p class="mt-1 whitespace-pre-line">{{ $quote->notes }}</p></div>
            @endif

            {{-- The director's name and the stamp place only: the stamp and signature images are in the manager's PDF. --}}
            <div class="mt-8 flex items-end justify-between gap-6 text-sm" data-signature-block>
                <p><span class="text-slate-500">{{ __('crm.quotes.pdf.director') }}</span><br><span class="font-semibold">{{ $legal['director'] }}</span></p>
                <p class="text-slate-500" data-stamp-place>{{ __('crm.quotes.pdf.stamp_place') }}</p>
            </div>
        </article>
    </main>
</body>
</html>
