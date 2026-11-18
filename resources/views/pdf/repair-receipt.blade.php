{{--
    Repair intake receipt PDF (plan 35, I2), rendered by GenerateRepairReceiptPdf in the receipt locale.
    Two pages: client copy, service copy (identical content). dompdf: DejaVu Sans, inline styles, data URIs only.
    All user text goes through {{ }} or nl2br(e()); no user text in CSS or attributes. Never costs, diagnosis,
    work, parts or the engineer (P35-9).
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('repairs.receipt.title') }} {{ $number }}</title>
    <style>
        @page { margin: 28px 36px 36px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0f172a; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        .page { page-break-after: always; }
        .page-last { page-break-after: auto; }
        .muted { color: #64748b; }
        .header td { vertical-align: top; }
        .logo { max-height: 52px; max-width: 170px; }
        .company { text-align: right; font-size: 9px; }
        .company b { font-size: 11px; }
        h1 { font-size: 17px; margin: 16px 0 2px; }
        .number { font-size: 20px; font-weight: bold; }
        .copy { display: inline-block; border: 1px solid #0f172a; padding: 1px 8px; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        h2 { font-size: 10px; text-transform: uppercase; color: #475569; letter-spacing: 0.5px; margin: 14px 0 4px; border-bottom: 1px solid #cbd5e1; padding-bottom: 2px; }
        .grid td { padding: 3px 6px 3px 0; vertical-align: top; border-bottom: 1px solid #e2e8f0; }
        .grid td.label { width: 30%; color: #64748b; }
        .box { border: 1px solid #e2e8f0; padding: 6px 8px; background: #f8fafc; }
        .terms { font-size: 9px; color: #334155; }
        .qr img { width: 120px; height: 120px; }
        .url { font-family: 'DejaVu Sans Mono', monospace; font-size: 8px; word-wrap: break-word; }
        .sign { margin-top: 16px; page-break-inside: avoid; }
        .sign td { vertical-align: bottom; width: 50%; padding-right: 16px; }
        .line { border-bottom: 1px solid #0f172a; height: 26px; }
        .caption { color: #64748b; font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; text-align: center; font-size: 8px; color: #64748b; }
        .page-number:before { content: counter(page); }
    </style>
</head>
<body>
<div class="footer">{{ $number }} · <span class="page-number"></span></div>
@foreach ($copies as $index => $copyLabel)
    <div class="page{{ $loop->last ? ' page-last' : '' }}">
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
                    <b>{{ $company['name'] }}</b><br>
                    @if ($company['address']){{ $company['address'] }}<br>@endif
                    @if ($company['phone']){{ $company['phone'] }}@endif
                    @if ($company['email']) · {{ $company['email'] }}@endif
                    @if ($company['phone'] || $company['email'])<br>@endif
                    @if ($company['hours']){{ $company['hours'] }}@endif
                </td>
            </tr>
        </table>

        <table style="margin-top: 6px">
            <tr>
                <td>
                    <h1>{{ __('repairs.receipt.title') }}</h1>
                    <span class="number">{{ $number }}</span>
                    <span class="muted"> · {{ __('repairs.receipt.received_at') }}: {{ $received }}</span>
                </td>
                <td style="text-align: right; vertical-align: top"><span class="copy">{{ $copyLabel }}</span></td>
            </tr>
        </table>

        <h2>{{ __('repairs.receipt.client') }}</h2>
        <table class="grid">
            <tr><td class="label">{{ __('repairs.receipt.name') }}</td><td>{{ $client['name'] }}</td></tr>
            <tr><td class="label">{{ __('repairs.receipt.phone') }}</td><td>{{ $client['phone'] }}</td></tr>
            @if (filled($client['company']))
                <tr><td class="label">{{ __('repairs.receipt.company') }}</td><td>{{ $client['company'] }}</td></tr>
            @endif
        </table>

        <h2>{{ __('repairs.receipt.device') }}</h2>
        <table class="grid">
            <tr><td class="label">{{ __('repairs.receipt.device_type') }}</td><td>{{ $device['type'] }}</td></tr>
            @if (filled($device['brand']))
                <tr><td class="label">{{ __('repairs.receipt.brand') }}</td><td>{{ $device['brand'] }}</td></tr>
            @endif
            @if (filled($device['model']))
                <tr><td class="label">{{ __('repairs.receipt.model') }}</td><td>{{ $device['model'] }}</td></tr>
            @endif
            <tr><td class="label">{{ __('repairs.receipt.serial') }}</td><td>{{ filled($device['serial']) ? $device['serial'] : '—' }}</td></tr>
            @if (filled($device['accessories']))
                <tr><td class="label">{{ __('repairs.receipt.accessories') }}</td><td>{!! nl2br(e($device['accessories'])) !!}</td></tr>
            @endif
            <tr><td class="label">{{ __('repairs.receipt.condition') }}</td><td>{!! nl2br(e($device['condition'])) !!}</td></tr>
        </table>

        <h2>{{ __('repairs.receipt.problem') }}</h2>
        <div class="box">{!! nl2br(e($problem)) !!}</div>

        <h2>{{ __('repairs.receipt.coverage') }}</h2>
        <div>
            <b>{{ $coverage }}</b>@if (filled($warrantyReference)) · {{ __('repairs.receipt.warranty_reference') }}: {{ $warrantyReference }}@endif
        </div>
        <div class="muted" style="margin-top: 3px">{{ $diagnosticsLine }}</div>

        <h2>{{ __('repairs.receipt.terms_title') }}</h2>
        <div class="terms">{!! nl2br(e($terms)) !!}</div>

        @if ($linkExpired)
            <div class="box" style="margin-top: 12px; page-break-inside: avoid" data-link-expired><b>{{ $linkExpiredLine }}</b></div>
        @else
            <table style="margin-top: 12px; page-break-inside: avoid">
                <tr>
                    <td class="qr" style="width: 135px; vertical-align: top"><img src="{{ $qr }}" alt=""></td>
                    <td style="vertical-align: top">
                        <b>{{ __('repairs.receipt.check_status') }}</b>
                        <div class="url" style="margin-top: 3px">{{ $statusUrl }}</div>
                        <div class="muted" style="margin-top: 6px">{{ $number }}</div>
                    </td>
                </tr>
            </table>
        @endif

        <table class="sign">
            <tr>
                <td colspan="2" style="padding-bottom: 4px"><b>{{ __('repairs.receipt.agree') }}</b></td>
            </tr>
            <tr>
                <td>
                    <div class="line"></div>
                    <div class="caption">{{ __('repairs.receipt.signature_client') }}</div>
                    <div class="line"></div>
                    <div class="caption">{{ __('repairs.receipt.signature_name') }}</div>
                    <div class="line"></div>
                    <div class="caption">{{ __('repairs.receipt.signature_date') }}</div>
                </td>
                <td>
                    <div class="line">{{ $receiver }}</div>
                    <div class="caption">{{ __('repairs.receipt.signature_receiver', ['company' => $receiverCompany]) }}</div>
                    <div class="line"></div>
                    <div class="caption">{{ __('repairs.receipt.signature_date') }}</div>
                </td>
            </tr>
        </table>

    </div>
@endforeach
</body>
</html>
