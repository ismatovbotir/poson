<?php

namespace App\Livewire\Site\Orders;

use App\Actions\Payments\Uzqr\CancelUzqrInvoice;
use App\Actions\Payments\Uzqr\RefreshUzqrInvoice;
use App\Actions\Payments\Uzqr\StartUzqrPayment;
use App\Actions\Payments\Uzqr\UzqrPaymentRefused;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UzqrSettlement;
use App\Enums\UzqrTransactionStatus;
use App\Models\Order;
use App\Models\UzqrTransaction;
use App\Support\Qr\QrRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Pay with UzQR" dialog on the customer's order page /o/{ulid} (plan 11e
 * §2–3, security conditions 37, 43, 44, 46, 49):
 *
 * - actions take no arguments; the order is re-resolved from the #[Locked]
 *   ULID on every call with the same rule as /o (neutral 404 for unknown,
 *   TEST, renewed or expired links);
 * - open() / newQr(): 10 a minute per IP (inside the methods, since
 *   /livewire/update bypasses route throttles), plus the per-order caps in
 *   StartUzqrPayment, newQr() also 3 an hour per order; refresh(): 40 a
 *   minute per IP and 60 per order,
 *   coalesced to one bank call per invoice per 3 s;
 * - everything shown comes from the DB; qr_text is never a public
 *   property: the QR is rendered server-side as an SVG data URI (PNG data
 *   URI for "save image"), only while the invoice is pending;
 * - the 3-second poll attribute is rendered only while pending and for
 *   the first 15 minutes, then a "Check payment status" button;
 * - payment is confirmed only by the server-to-server status call.
 */
class UzqrPayment extends Component
{
    #[Locked]
    public string $ulid = '';

    #[Locked]
    public string $locale = 'uz';

    public bool $show = false;

    /**
     * Last refusal code (lang uzqr.errors.*), never bank text; set by the server only.
     */
    #[Locked]
    public ?string $notice = null;

    public function mount(string $ulid, string $locale, bool $autoOpen = false): void
    {
        $this->ulid = strtoupper($ulid);
        $this->locale = in_array($locale, (array) config('app.supported_locales'), true) ? $locale : (string) config('app.locale');
        $this->show = $autoOpen;
    }

    public function boot(): void
    {
        if (in_array($this->locale, (array) config('app.supported_locales'), true)) {
            app()->setLocale($this->locale);
        }
    }

    public function open(StartUzqrPayment $start): void
    {
        $order = $this->order();
        $this->show = true;
        $this->notice = null;

        if (! $this->hit('uzqr-open:'.sha1((string) request()->ip()), (int) config('uzqr.limits.open_per_ip_minute', 10))) {
            return;
        }

        try {
            $start->handle($order);
        } catch (UzqrPaymentRefused $refused) {
            $this->notice = $refused->reason;
        }
    }

    public function refresh(RefreshUzqrInvoice $refresh): void
    {
        $order = $this->order();

        if (! $this->hit('uzqr-poll:'.sha1((string) request()->ip()), (int) config('uzqr.limits.refresh_per_ip_minute', 40))
            || ! $this->hit('uzqr-poll-order:'.$order->id, (int) config('uzqr.limits.refresh_per_order_minute', 60))) {
            return;
        }

        $transaction = $this->holding($order);

        if ($transaction !== null) {
            $refresh->handle($transaction, 'poll');
        }
    }

    /**
     * A new QR after the previous one ended; an invoice still open at the bank
     * is cancelled first (condition 39). Refused server-side while the current
     * QR is still payable (creating / pending before expiry), so a crafted call
     * can't cancel a live QR; at most 3 per order an hour (change review M3).
     */
    public function newQr(CancelUzqrInvoice $cancel, StartUzqrPayment $start): void
    {
        $order = $this->order();
        $this->show = true;
        $this->notice = null;

        $holding = $this->holding($order);

        if ($holding !== null && $this->stillPayable($holding)) {
            $this->notice = 'qr_still_valid';

            return;
        }

        if (! $this->hit('uzqr-open:'.sha1((string) request()->ip()), (int) config('uzqr.limits.open_per_ip_minute', 10))) {
            return;
        }

        // The per-order cap counts only QRs actually started (code review #3), never refused / cancel_pending calls.
        $orderCapKey = 'uzqr-new-order:'.$order->id;

        if (RateLimiter::tooManyAttempts($orderCapKey, (int) config('uzqr.limits.new_qr_per_order_hour', 3))) {
            $this->notice = 'order_cap';

            return;
        }

        try {
            if ($holding !== null) {
                $holding = $cancel->handle($holding);

                if ($holding->holdsOrder()) {
                    $this->notice = 'cancel_pending';

                    return;
                }
            }

            $start->handle($order->refresh());
            RateLimiter::hit($orderCapKey, 3600);
        } catch (UzqrPaymentRefused $refused) {
            $this->notice = $refused->reason;
        }
    }

    public function close(): void
    {
        $this->show = false;
    }

    public function render(QrRenderer $renderer): View
    {
        $order = $this->order();
        $transaction = UzqrTransaction::query()->where('order_id', $order->id)->latest('id')->first();
        $state = $this->state($order, $transaction);
        $qrSvg = $qrPng = null;

        if ($state === 'pending' && $transaction?->qr_text !== null && $transaction->expires_at !== null) {
            // Rendered once per invoice, not on every 3-second poll (change review M2).
            ['svg' => $qrSvg, 'png' => $qrPng] = Cache::remember('uzqr:qr:'.$transaction->id.':'.$transaction->invoice_id, $transaction->expires_at, fn (): array => [
                'svg' => $renderer->svgDataUri((string) $transaction->qr_text),
                'png' => $renderer->pngDataUri((string) $transaction->qr_text),
            ]);
        }

        return view('livewire.site.orders.uzqr-payment', [
            'order' => $order,
            'transaction' => $transaction,
            'state' => $state,
            'qrSvg' => $qrSvg,
            'qrPng' => $qrPng,
            'secondsLeft' => $state === 'pending' && $transaction?->expires_at !== null ? max(0, (int) now()->diffInSeconds($transaction->expires_at, false)) : 0,
            'poll' => $this->show && in_array($state, ['pending', 'creating'], true)
                && $transaction !== null && $transaction->created_at->greaterThan(now()->subMinutes((int) config('uzqr.auto_poll_minutes', 15))),
        ]);
    }

    /**
     * paid | received (unmatched) | cancelled | start | creating | pending | waiting (expired, bank not final) | ended.
     */
    protected function state(Order $order, ?UzqrTransaction $transaction): string
    {
        return match (true) {
            $order->payment_status === PaymentStatus::Paid || $order->payment_status === PaymentStatus::Refunded => 'paid',
            $transaction?->settlement === UzqrSettlement::Unmatched => 'received',
            $order->status === OrderStatus::Cancelled => 'cancelled',
            $transaction === null => 'start',
            $transaction->status === UzqrTransactionStatus::Creating => 'creating',
            $transaction->status === UzqrTransactionStatus::Pending && $transaction->expires_at?->isFuture() === true => 'pending',
            $transaction->holdsOrder() => 'waiting',
            default => 'ended',
        };
    }

    /**
     * The order behind a link that still works (same rule as /o), else a neutral 404.
     */
    protected function order(): Order
    {
        $order = Order::query()->where('ulid', $this->ulid)->first();

        abort_unless($order instanceof Order && $order->isPublicLinkValid() && $order->payment_method === PaymentMethod::UzqrDynamic, 404);

        return $order;
    }

    protected function holding(Order $order): ?UzqrTransaction
    {
        return UzqrTransaction::query()->where('order_id', $order->id)->holding()->latest('id')->first();
    }

    /**
     * Creating, or pending before its expiry: the customer may be paying it right now.
     */
    protected function stillPayable(UzqrTransaction $transaction): bool
    {
        return $transaction->status === UzqrTransactionStatus::Creating
            || ($transaction->status === UzqrTransactionStatus::Pending && $transaction->expires_at?->isFuture() === true);
    }

    protected function hit(string $key, int $max, int $decaySeconds = 60, string $notice = 'too_many_short'): bool
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->notice = $notice;

            return false;
        }

        RateLimiter::hit($key, $decaySeconds);

        return true;
    }
}
