<?php

namespace App\Actions\Quotes;

use App\Models\Quote;
use App\Models\Setting;
use App\Support\CompanySettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Http\Response;

/**
 * The quote as a PDF (plan 06), with dompdf and DejaVu Sans (Cyrillic and
 * the Uzbek ʻ / ʼ marks), in the quote's locale:
 *
 *  - header: company logo (PNG / JPEG original) and requisites from
 *    CompanySettings (legal name, STIR, bank, MFO, account, address, phone);
 *  - number, date, valid until, customer requisites, the lines and the
 *    total "including VAT 12%", notes;
 *  - signature block: the director's name and the stamp place ("M.O."); the
 *    stamp + signature images (private PNG uploads, embedded as data URIs,
 *    never a URL) only in the signed admin download.
 *
 * The only prices are the quote's own lines (no other level, no list price).
 * Hardened: no remote resources, no PHP, no JavaScript, chroot to an empty
 * folder; everything is inline.
 */
class GenerateQuotePdf
{
    /**
     * Folder dompdf may read local files from: empty on purpose. Images are
     * data URIs, fonts come from dompdf's own font directory.
     */
    public static function chroot(): string
    {
        return resource_path('pdf');
    }

    /**
     * The PDF's HTML in $locale (default: the quote's). $signed adds the
     * stamp and director signature: only for the PDF a manager downloads in
     * admin (user decision), never on the public page or public PDF.
     */
    public function html(Quote $quote, ?string $locale = null, bool $signed = false): string
    {
        $locale = static::locale($quote, $locale);
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return view('pdf.quote', $this->data($quote, $locale, $signed))->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function pdf(Quote $quote, ?string $locale = null, bool $signed = false): DomPdf
    {
        return Pdf::setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'chroot' => static::chroot(),
            'defaultFont' => 'DejaVu Sans',
        ])
            ->loadHTML($this->html($quote, $locale, $signed))
            ->setPaper('a4');
    }

    public function download(Quote $quote, ?string $locale = null, bool $signed = false): Response
    {
        return $this->pdf($quote, $locale, $signed)->download($quote->number.'.pdf');
    }

    /**
     * View data (also used by the public page, never signed there).
     *
     * @return array<string, mixed>
     */
    public function data(Quote $quote, ?string $locale = null, bool $signed = false): array
    {
        $locale = static::locale($quote, $locale);
        $quote->loadMissing(['items', 'customer.primaryContact', 'manager:id,name']);

        $company = app(CompanySettings::class);

        return [
            'quote' => $quote,
            'locale' => $locale,
            'company' => [
                'name' => $company->name($locale),
                'legal' => $company->legal($locale),
                'phone' => $company->phoneDisplay(),
                'email' => $company->email(),
                'address' => $company->address($locale),
            ],
            'logo' => Setting::originalDataUri(Setting::LOGO, ['image/png', 'image/jpeg']),
            'stamp' => $signed ? Setting::privateImageDataUri(Setting::STAMP) : null,
            'signature' => $signed ? Setting::privateImageDataUri(Setting::SIGNATURE) : null,
        ];
    }

    private static function locale(Quote $quote, ?string $locale): string
    {
        return in_array($locale, config('app.supported_locales'), true) ? (string) $locale : $quote->locale;
    }
}
