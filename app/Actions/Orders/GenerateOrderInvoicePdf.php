<?php

namespace App\Actions\Orders;

use App\Actions\Quotes\GenerateQuotePdf;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Orders\CheckoutSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Http\Response;

/**
 * An order's invoice as a PDF (plan 11 v2 §1.11, security condition 8),
 * hardened like the quote PDF (no remote resources, PHP or JavaScript,
 * empty chroot, images as data URIs only), in the order's locale or ?lang:
 *
 * invoice number (with the reissue suffix), date, valid until, seller
 * requisites, the buyer's company name and tax id (business buyers only),
 * the lines (roll lines described), the delivery (a fixed line, or
 * "confirmed by the manager and billed separately"), the total including
 * VAT 12%, the payment purpose "Payment for order № O-…" and, for static
 * UzQR, the code with its recipient and the exact amount.
 *
 * The public /o/{ulid}/invoice.pdf is always unsigned; the stamp and the
 * director's signature only in the admin download after confirmation.
 */
class GenerateOrderInvoicePdf
{
    public function __construct(protected CheckoutSettings $settings) {}

    public function html(Order $order, ?string $locale = null, bool $signed = false): string
    {
        $locale = static::locale($order, $locale);
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return view('pdf.order-invoice', $this->data($order, $locale, $signed))->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    public function pdf(Order $order, ?string $locale = null, bool $signed = false): DomPdf
    {
        return Pdf::setOption([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'chroot' => GenerateQuotePdf::chroot(),
            'defaultFont' => 'DejaVu Sans',
        ])
            ->loadHTML($this->html($order, $locale, $signed))
            ->setPaper('a4');
    }

    public function download(Order $order, ?string $locale = null, bool $signed = false): Response
    {
        return $this->pdf($order, $locale, $signed)->download(str_replace('/', '-', $order->invoiceNumber()).'.pdf');
    }

    /**
     * Invoices exist for bank transfer and static UzQR orders.
     */
    public static function hasInvoice(Order $order): bool
    {
        return in_array($order->payment_method, [PaymentMethod::BankTransfer, PaymentMethod::UzqrStatic], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Order $order, ?string $locale = null, bool $signed = false): array
    {
        $locale = static::locale($order, $locale);
        $order->loadMissing('items');
        $company = app(CompanySettings::class);
        $uzqr = $order->payment_method === PaymentMethod::UzqrStatic ? $this->settings->uzqrDataUri() : null;

        return [
            'order' => $order,
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
            'uzqr' => $uzqr,
            'uzqrRecipient' => $uzqr !== null ? $this->settings->uzqrRecipient() : null,
        ];
    }

    private static function locale(Order $order, ?string $locale): string
    {
        return in_array($locale, config('app.supported_locales'), true) ? (string) $locale : $order->locale;
    }
}
