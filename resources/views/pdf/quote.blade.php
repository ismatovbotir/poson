{{--
    Quote PDF (plan 06), rendered by GenerateQuotePdf in the requested locale.
    Stamp and signature images only when signed (the admin download); otherwise the stamp place.
    dompdf: DejaVu Sans for Cyrillic and ʻ ʼ, inline styles, images as data URIs only.
--}}
@php
    /** @var \App\Models\Quote $quote */
    $customer = $quote->customer;
    $legal = $company['legal'];
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
    $date = fn ($value) => $value ? \App\Support\DateFormat::iso($value, 'D MMMM YYYY') : '';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('crm.quotes.pdf.title') }} {{ $quote->number }}</title>
    <style>
        @page { margin: 28px 36px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0f172a; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .header td { vertical-align: top; }
        .logo { max-height: 56px; max-width: 180px; }
        .company { text-align: right; font-size: 9px; }
        .company b { font-size: 11px; }
        h1 { font-size: 18px; margin: 22px 0 2px; }
        .meta td { padding: 2px 0; }
        .box { border: 1px solid #e2e8f0; padding: 8px 10px; margin-top: 12px; }
        .items { margin-top: 14px; }
        .items th { background: #f1f5f9; font-size: 9px; text-transform: uppercase; color: #475569; text-align: left; padding: 6px; border-bottom: 1px solid #cbd5e1; }
        .items td { padding: 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals td { padding: 3px 6px; }
        .grand td { font-size: 12px; font-weight: bold; border-top: 1px solid #0f172a; padding-top: 6px; }
        .notes { margin-top: 14px; white-space: pre-line; }
        .sign { margin-top: 28px; }
        .sign td { vertical-align: bottom; }
        .stamp { height: 110px; }
        .stamp-place { color: #64748b; font-size: 9px; }
        .caption { color: #64748b; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .signature { height: 50px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 45%">
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" alt="">
                @else
                    <b style="font-size: 16px">{{ $company['name'] }}</b>
                @endif
            </td>
            <td class="company">
                <span class="caption">{{ __('crm.quotes.pdf.supplier') }}</span><br>
                <b>{{ $legal['legal_name'] ?: $company['name'] }}</b><br>
                @if ($legal['inn']){{ __('crm.quotes.pdf.inn') }}: {{ $legal['inn'] }}<br>@endif
                @if ($legal['bank']){{ __('crm.quotes.pdf.bank') }}: {{ $legal['bank'] }}<br>@endif
                @if ($legal['mfo']){{ __('crm.quotes.pdf.mfo') }}: {{ $legal['mfo'] }}@endif
                @if ($legal['account']) · {{ __('crm.quotes.pdf.account') }}: {{ $legal['account'] }}@endif
                @if ($legal['mfo'] || $legal['account'])<br>@endif
                @if ($legal['legal_address'] ?: $company['address']){{ $legal['legal_address'] ?: $company['address'] }}<br>@endif
                @if ($company['phone']){{ $company['phone'] }}@endif
                @if ($company['email']) · {{ $company['email'] }}@endif
            </td>
        </tr>
    </table>

    <h1>{{ __('crm.quotes.pdf.title') }} {{ $quote->number }}</h1>
    <table class="meta">
        <tr><td class="muted" style="width: 30%">{{ __('crm.quotes.pdf.date') }}</td><td>{{ $date($quote->created_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE)) }}</td></tr>
        <tr><td class="muted">{{ __('crm.quotes.pdf.valid_until') }}</td><td><b>{{ $date($quote->valid_until) }}</b></td></tr>
        @if ($quote->manager)
            <tr><td class="muted">{{ __('crm.quotes.pdf.manager') }}</td><td>{{ $quote->manager->name }}</td></tr>
        @endif
    </table>

    <div class="box">
        <span class="muted">{{ __('crm.quotes.pdf.customer') }}</span><br>
        <b>{{ $customer->name }}</b><br>
        @if ($customer->inn){{ __('crm.quotes.pdf.inn') }}: {{ $customer->inn }}<br>@endif
        @if ($customer->address){{ $customer->address }}<br>@endif
        @if ($customer->primaryContact){{ $customer->primaryContact->name }}@if ($customer->primaryContact->phone) · {{ \App\Support\Phone::display($customer->primaryContact->phone) }}@endif<br>@endif
        @if ($customer->phone && ! $customer->primaryContact){{ \App\Support\Phone::display($customer->phone) }}@endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%">{{ __('crm.quotes.pdf.line_number') }}</th>
                <th>{{ __('crm.quotes.pdf.item') }}</th>
                <th class="num" style="width: 9%">{{ __('crm.quotes.pdf.qty') }}</th>
                <th style="width: 9%">{{ __('crm.quotes.pdf.unit') }}</th>
                <th class="num" style="width: 18%">{{ __('crm.quotes.pdf.price') }}</th>
                <th class="num" style="width: 18%">{{ __('crm.quotes.pdf.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->name }}@if ($item->sku)<br><span class="muted">{{ $item->sku }}</span>@endif</td>
                    <td class="num">{{ \App\Support\NumberFormat::format($item->qty, $locale) }}</td>
                    <td>{{ $item->unit?->label() }}</td>
                    <td class="num">{{ $money($item->price) }}</td>
                    <td class="num">{{ $money($item->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 6px">
        <tr class="grand"><td style="width: 64%"></td><td>{{ __('crm.quotes.pdf.grand_total') }}</td><td class="num">{{ $money($quote->total) }}</td></tr>
        <tr><td></td><td class="muted">{{ __('crm.quotes.pdf.vat', ['percent' => \App\Models\Quote::VAT_PERCENT]) }}</td><td class="num muted">{{ $money($quote->vat_amount) }}</td></tr>
    </table>

    @if (filled($quote->notes))
        <div class="notes"><b>{{ __('crm.quotes.pdf.notes') }}</b><br>{{ $quote->notes }}</div>
    @endif

    <table class="sign">
        <tr>
            <td style="width: 45%">
                {{ __('crm.quotes.pdf.director') }}<br>
                <b>{{ $legal['director'] }}</b>
            </td>
            <td style="width: 25%">
                @if ($signature)<img src="{{ $signature }}" class="signature" alt="" data-signature>@endif
            </td>
            <td>
                @if ($stamp)
                    <img src="{{ $stamp }}" class="stamp" alt="" data-stamp>
                @else
                    <span class="stamp-place" data-stamp-place>{{ __('crm.quotes.pdf.stamp_place') }}</span>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
