<?php

namespace App\Http\Controllers\Site;

use App\Actions\Orders\GenerateOrderInvoicePdf;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\PaymentOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The customer's order link /o/{ulid} (plan 11 v2 §1.10, security
 * conditions 10, 19, 23, 25, 27, 28): no login, a random ULID separate from
 * the order id, no locale prefix (?lang=uz|ru|en), noindex + no-store +
 * no-referrer, throttled (page 60/min, invoice 10/min, pay 10/min), kept
 * out of analytics and robots.txt.
 *
 * Unknown links, TEST orders, renewed (old) links and links past
 * public_until all get the same neutral 404 ("link not valid or expired,
 * contact us") from the same lookup, so they can't be told apart.
 *
 * Shows only what the payer needs: number, date, statuses, lines, totals
 * with VAT, delivery type and region (never the address), and the payment
 * instructions (static UzQR code with recipient, exact amount and purpose;
 * bank requisites + the unsigned invoice; a Pay button for an online
 * method while payable; on-receipt text), an outstanding delivery charge
 * and "Amount updated" after a reissue. Never the buyer's name, phone,
 * company or tax id (the invoice PDF carries company + tax id). No "I've
 * paid" button: staff confirm against the bank statement.
 *
 * Dynamic UzQR (plan 11e): the "Pay with UzQR" Livewire dialog
 * (Site\Orders\UzqrPayment) is the only goods payment shown; ?pay=uzqr opens
 * it. Paid only by the bank status call, never by a URL parameter.
 */
class PublicOrderController extends Controller
{
    public const ROBOTS = 'noindex, nofollow, noarchive';

    public function show(Request $request, string $ulid, CheckoutSettings $settings, PaymentOptions $paymentOptions, CompanySettings $company): SymfonyResponse
    {
        $order = $this->find($ulid);

        if ($order === null) {
            return $this->notFound($request);
        }

        $locale = $this->locale($request, $order);
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $order->load(['items', 'payments']);
            $cancelled = $order->status === OrderStatus::Cancelled;

            $html = view('site.order', [
                'order' => $order,
                'locale' => $locale,
                'company' => [
                    'name' => $company->name($locale),
                    'legal' => $company->legal($locale),
                    'phone' => $company->phoneDisplay(),
                    'email' => $company->email(),
                ],
                'logo' => Setting::originalDataUri(Setting::LOGO, ['image/png', 'image/jpeg']),
                'uzqr' => $settings->uzqrDataUri(),
                'uzqrRecipient' => $settings->uzqrRecipient(),
                // A cancelled order shows no way to pay (security review M1).
                'cancelled' => $cancelled,
                'payable' => $order->isPayable(),
                'charge' => $cancelled ? null : $order->pendingDeliveryCharge(),
                'canPayOnline' => $paymentOptions->canPayOnline($order),
                'hasInvoice' => ! $cancelled && GenerateOrderInvoicePdf::hasInvoice($order),
                'requisites' => $paymentOptions->requisitesComplete(),
                // ?pay=uzqr (after checkout) only opens the dialog; the QR is created on a click.
                'autoOpenUzqr' => $request->query('pay') === 'uzqr',
            ])->render();
        } finally {
            app()->setLocale($previous);
        }

        return $this->private(new Response($html));
    }

    public function invoice(Request $request, string $ulid, GenerateOrderInvoicePdf $generatePdf): SymfonyResponse
    {
        $order = $this->find($ulid);

        if ($order === null || $order->status === OrderStatus::Cancelled || ! GenerateOrderInvoicePdf::hasInvoice($order)) {
            return $this->notFound($request);
        }

        // Always unsigned here (security condition 8).
        return $this->private($generatePdf->download($order, $this->locale($request, $order), signed: false));
    }

    /**
     * "Pay" for an online method: re-checks the link, the payment state and
     * the matrix. The provider redirect arrives with plans 11c / 11d; until
     * then the page explains that online payment is not available.
     */
    public function pay(Request $request, string $ulid, PaymentOptions $paymentOptions): SymfonyResponse
    {
        $order = $this->find($ulid);

        if ($order === null) {
            return $this->notFound($request);
        }

        $back = route('orders.public', ['ulid' => $order->ulid, 'lang' => $this->locale($request, $order)]);
        $payable = $paymentOptions->canPayOnline($order);

        $response = new RedirectResponse($back);

        if (! $payable) {
            $request->session()->flash('order_notice', 'pay_unavailable');
        }

        return $this->private($response);
    }

    /**
     * The order behind a link that still works, else null (one lookup for every case).
     */
    private function find(string $ulid): ?Order
    {
        $order = Order::query()->where('ulid', strtoupper($ulid))->first();

        return $order instanceof Order && $order->isPublicLinkValid() ? $order : null;
    }

    private function locale(Request $request, Order $order): string
    {
        $lang = $request->query('lang');

        return is_string($lang) && in_array($lang, config('app.supported_locales'), true) ? $lang : $order->locale;
    }

    private function notFound(Request $request): SymfonyResponse
    {
        $lang = $request->query('lang');
        $locale = is_string($lang) && in_array($lang, config('app.supported_locales'), true) ? $lang : (string) config('app.locale');
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = view('site.order-missing', ['locale' => $locale, 'phone' => app(CompanySettings::class)->phoneDisplay()])->render();
        } finally {
            app()->setLocale($previous);
        }

        return $this->private(new Response($html, 404));
    }

    private function private(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('X-Robots-Tag', self::ROBOTS);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
