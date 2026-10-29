{{--
    Order invoice PDF (plan 11 v2 §1.11), rendered by GenerateOrderInvoicePdf in the order's locale.
    Public download: never signed. Stamp and signature only in the admin download after confirmation.
    dompdf: DejaVu Sans for Cyrillic and ʻ ʼ, inline styles, images as data URIs only.
--}}
@php
    /** @var \App\Models\Order $order */
    $legal = $company['legal'];
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
    $date = fn ($value) => $value ? \App\Support\DateFormat::iso($value, 'D MMMM YYYY') : '';
    $separate = $order->delivery_pricing === \App\Enums\DeliveryPricing::Separate;
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('orders.invoice.title') }} {{ $order->invoiceNumber() }}</title>
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
        .purpose { margin-top: 14px; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .qr { margin-top: 14px; }
        .qr img { width: 150px; height: 150px; }
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
                <span class="caption">{{ __('orders.invoice.supplier') }}</span><br>
                <b>{{ $legal['legal_name'] ?: $company['name'] }}</b><br>
                @if ($legal['inn']){{ __('orders.invoice.inn') }}: {{ $legal['inn'] }}<br>@endif
                @if ($legal['vat_code']){{ __('orders.invoice.vat_code') }}: {{ $legal['vat_code'] }}<br>@endif
                @if ($legal['bank']){{ __('orders.invoice.bank') }}: {{ $legal['bank'] }}<br>@endif
                @if ($legal['mfo']){{ __('orders.invoice.mfo') }}: {{ $legal['mfo'] }}@endif
                @if ($legal['account']) · {{ __('orders.invoice.account') }}: {{ $legal['account'] }}@endif
                @if ($legal['mfo'] || $legal['account'])<br>@endif
                @if ($legal['legal_address'] ?: $company['address']){{ $legal['legal_address'] ?: $company['address'] }}<br>@endif
                @if ($company['phone']){{ $company['phone'] }}@endif
                @if ($company['email']) · {{ $company['email'] }}@endif
            </td>
        </tr>
    </table>

    <h1>{{ __('orders.invoice.title') }} {{ $order->invoiceNumber() }}</h1>
    <table class="meta">
        <tr><td class="muted" style="width: 30%">{{ __('orders.invoice.date') }}</td><td>{{ $date($order->created_at->copy()->setTimezone(\App\Models\Lead::TIMEZONE)) }}</td></tr>
        @if ($order->invoice_valid_until)
            <tr><td class="muted">{{ __('orders.invoice.valid_until') }}</td><td><b>{{ $date($order->invoice_valid_until) }}</b></td></tr>
        @endif
        <tr><td class="muted">{{ __('orders.invoice.order') }}</td><td>{{ $order->number }}</td></tr>
    </table>

    <div class="box" data-buyer>
        <span class="muted">{{ __('orders.invoice.buyer') }}</span><br>
        @if ($order->buyer_type->isBusiness())
            <b>{{ $order->company_name }}</b><br>
            @if ($order->tin){{ __('orders.invoice.tin') }}: {{ $order->tin }}@endif
        @else
            {{ $order->buyer_type->label() }}
        @endif
    </div>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%">№</th>
                <th>{{ __('orders.invoice.item') }}</th>
                <th class="num" style="width: 9%">{{ __('orders.invoice.qty') }}</th>
                <th style="width: 9%">{{ __('orders.invoice.unit') }}</th>
                <th class="num" style="width: 18%">{{ __('orders.invoice.price') }}</th>
                <th class="num" style="width: 18%">{{ __('orders.invoice.total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->name }}@if ($item->isRollLine())<br><span data-roll-line>{{ $item->rollDescription($locale, withRolls: false) }}</span>@endif @if ($item->sku)<br><span class="muted">{{ $item->sku }}</span>@endif</td>
                    <td class="num">{{ \App\Support\NumberFormat::format($item->qty, $locale) }}</td>
                    <td>{{ $item->unit?->label() }}</td>
                    <td class="num">{{ $money($item->price) }}</td>
                    <td class="num">{{ $money($item->total) }}</td>
                </tr>
            @endforeach
            @if (! $separate && $order->delivery_amount > 0)
                <tr data-delivery-line>
                    <td>{{ $order->items->count() + 1 }}</td>
                    <td>{{ __('orders.invoice.delivery_line', ['type' => $order->delivery_type->label()]) }}@if ($order->region) · {{ $order->region->label() }}@endif</td>
                    <td class="num">1</td>
                    <td>{{ __('orders.invoice.service_unit') }}</td>
                    <td class="num">{{ $money($order->delivery_amount) }}</td>
                    <td class="num">{{ $money($order->delivery_amount) }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    <table class="totals" style="margin-top: 6px">
        <tr class="grand"><td style="width: 64%"></td><td>{{ __('orders.invoice.grand_total') }}</td><td class="num" data-total>{{ $money($order->total) }}</td></tr>
        <tr><td></td><td class="muted">{{ __('orders.invoice.vat', ['percent' => \App\Support\Vat::PERCENT]) }}</td><td class="num muted">{{ $money($order->vat_amount) }}</td></tr>
    </table>

    @if ($separate)
        <p class="muted" data-delivery-separate>{{ __('orders.public.delivery_separate') }}</p>
    @endif

    <div class="purpose">
        <b>{{ __('orders.invoice.purpose_label') }}:</b> {{ __('orders.public.purpose', ['number' => $order->number]) }}
    </div>

    @if ($uzqr)
        <table class="qr" data-uzqr>
            <tr>
                <td style="width: 170px"><img src="{{ $uzqr }}" alt=""></td>
                <td>
                    <b>{{ __('orders.public.uzqr_title') }}</b><br>
                    @if ($uzqrRecipient){{ __('orders.public.uzqr_recipient') }}: {{ $uzqrRecipient }}<br>@endif
                    {{ __('orders.public.exact_amount') }}: <b>{{ $money($order->total) }}</b><br>
                    {{ __('orders.invoice.purpose_label') }}: {{ __('orders.public.purpose', ['number' => $order->number]) }}
                </td>
            </tr>
        </table>
    @endif

    <table class="sign">
        <tr>
            <td style="width: 45%">
                {{ __('orders.invoice.director') }}<br>
                <b>{{ $legal['director'] }}</b>
            </td>
            <td style="width: 25%">
                @if ($signature)<img src="{{ $signature }}" class="signature" alt="" data-signature>@endif
            </td>
            <td>
                @if ($stamp)
                    <img src="{{ $stamp }}" class="stamp" alt="" data-stamp>
                @else
                    <span class="stamp-place" data-stamp-place>{{ __('orders.invoice.stamp_place') }}</span>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
