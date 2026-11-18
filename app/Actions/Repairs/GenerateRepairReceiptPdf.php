<?php

namespace App\Actions\Repairs;

use App\Actions\Quotes\GenerateQuotePdf;
use App\Exceptions\QrUnavailableException;
use App\Models\Lead;
use App\Models\Repair;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\DateFormat;
use App\Support\Phone;
use App\Support\Qr\QrRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The repair intake receipt as an A4 PDF (plan 35, I2): two pages (client
 * copy, service copy) in the chosen locale, dompdf with the quote PDF's
 * hardening (no remote, no PHP, no JS, empty chroot, DejaVu Sans).
 *
 * The view gets an allow-listed array only (P35-9): never the diagnosis,
 * work done, parts, any amount or the assigned engineer. The QR is a PNG
 * data URI; without GD it fails closed with a QrUnavailableException, never SVG
 * (P35-8). Nothing is written to disk.
 */
class GenerateRepairReceiptPdf
{
    public function __construct(
        protected QrRenderer $qr,
        protected EnsureRepairStatusToken $ensureToken,
    ) {}

    public function html(Repair $repair, ?string $locale = null): string
    {
        $locale = static::locale($repair, $locale);
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return view('pdf.repair-receipt', $this->data($repair, $locale))->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function pdf(Repair $repair, ?string $locale = null): DomPdf
    {
        return Pdf::setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'chroot' => GenerateQuotePdf::chroot(),
            'defaultFont' => 'DejaVu Sans',
        ])
            ->loadHTML($this->html($repair, $locale))
            ->setPaper('a4');
    }

    /**
     * Inline PDF for the browser tab; the file name is the number only.
     */
    public function stream(Repair $repair, ?string $locale = null): Response
    {
        return $this->pdf($repair, $locale)->stream($repair->number.'.pdf');
    }

    /**
     * Allow-listed view data. Call inside the receipt locale (html() does).
     *
     * @return array<string, mixed>
     *
     * @throws QrUnavailableException when the QR cannot be rendered as PNG
     */
    public function data(Repair $repair, ?string $locale = null): array
    {
        $locale = static::locale($repair, $locale);
        $repair->loadMissing('createdBy:id,name');

        // The controller issues the token first; this cheap fallback covers other callers (tests, tinker).
        if ($repair->public_token === null) {
            $this->ensureToken->handle($repair);
        }

        $linkLive = $repair->isStatusLinkLive();
        $url = $linkLive ? (string) $repair->statusUrl() : '';
        $qr = $linkLive ? $this->qr->pngDataUri($url) : null;

        if ($linkLive && $qr === null) {
            throw new QrUnavailableException(__('repairs.receipt.no_gd'));
        }

        $company = app(CompanySettings::class);
        $date = ($repair->received_at ?? $repair->created_at)?->copy()->setTimezone(Lead::TIMEZONE);

        return [
            'locale' => $locale,
            'number' => $repair->number,
            'received' => $date ? (string) DateFormat::iso($date, 'D MMMM YYYY, HH:mm', $locale) : '',
            'copies' => [__('repairs.receipt.copy_client'), __('repairs.receipt.copy_service')],
            'company' => [
                'name' => $company->name($locale),
                'phone' => $company->phoneDisplay(),
                'email' => $company->email(),
                'address' => $company->address($locale),
                'hours' => $company->workingHours($locale),
            ],
            'logo' => Setting::originalDataUri(Setting::LOGO, ['image/png', 'image/jpeg']),
            'client' => [
                'name' => $repair->contact_name,
                'phone' => Phone::display($repair->contact_phone),
                'company' => $repair->company,
            ],
            'device' => [
                'type' => $repair->device_type->label(),
                'brand' => static::cell($repair->device_brand, 100),
                'model' => static::cell($repair->device_model, 100),
                'serial' => static::cell($repair->serial_number, 100),
                'accessories' => $repair->accessories,
                'condition' => static::cell($repair->intake_condition, 1000),
            ],
            'problem' => $repair->problem,
            'coverage' => $repair->coverage->label(),
            'warrantyReference' => $repair->warranty_reference,
            'diagnosticsLine' => __('repairs.receipt.diagnostics_first'),
            'terms' => __('repairs.receipt.terms'),
            'qr' => $qr,
            'linkExpired' => ! $linkLive,
            'linkExpiredLine' => __('repairs.receipt.link_expired'),
            'statusUrl' => $url,
            'receiver' => $repair->createdBy?->name,
            'receiverCompany' => filled($company->legal($locale)['legal_name']) ? $company->legal($locale)['legal_name'] : 'Poson',
        ];
    }

    /**
     * A table-cell value: capped length, and a zero-width space every 40
     * characters in unbroken runs (words only, spaces are kept) so dompdf wraps it instead of overflowing.
     * Escape the result with e() / {{ }} afterwards.
     */
    private static function cell(?string $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\S{41,}/u',
            fn (array $run): string => implode("\u{200B}", mb_str_split($run[0], 40)),
            Str::limit($value, $max, '…'),
        );
    }

    private static function locale(Repair $repair, ?string $locale): string
    {
        return in_array($locale, config('app.supported_locales'), true) ? (string) $locale : (string) $repair->locale;
    }
}
