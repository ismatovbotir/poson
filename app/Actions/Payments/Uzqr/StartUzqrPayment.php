<?php

namespace App\Actions\Payments\Uzqr;

use App\Actions\Orders\LogOrderActivity;
use App\Actions\Payments\Uzqr\Concerns\LocksUzqrTransaction;
use App\Enums\ActivityType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\UzqrTransactionStatus;
use App\Models\Lead;
use App\Models\Order;
use App\Models\User;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrAlerts;
use App\Services\Payments\Uzqr\UzqrClient;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Services\Payments\Uzqr\UzqrException;
use App\Services\Payments\Uzqr\UzqrQrText;
use App\Support\Orders\PaymentOptions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Show a dynamic UzQR for an order (plan 11e §3, conditions 36–38, 42–44):
 *
 * 1. Under the order lock: an invoice that still holds the order (creating,
 *    pending, unconfirmed expired) is returned — never two per order.
 *    Otherwise check the method, unpaid + not cancelled + no other hold,
 *    the mode (real order ↔ prod, TEST ↔ test) and the matrix (configured,
 *    no auth flag, open-invoice cap, online total), the per-order caps
 *    (5 a Tashkent day, 3 an hour), then insert a "creating" row with the
 *    order total and external_id PS/PT + row id; commit.
 * 2. invoice/create outside any lock (retries with the same external_id).
 * 3. Under order → row lock: store invoice_id + qr_text (validated, EMVCo
 *    amount must match), pending, expiry 24 h − 2 min; the order goes
 *    "pending". Transient errors keep "creating" (retried on the next open
 *    within 2 min, then create_failed); permanent ones → create_failed.
 */
class StartUzqrPayment
{
    use LocksUzqrTransaction;

    public const RETRY_AFTER_SECONDS = 10;

    public function __construct(
        protected UzqrConfig $config,
        protected UzqrClient $client,
        protected PaymentOptions $paymentOptions,
        protected UzqrAlerts $alerts,
        protected LogOrderActivity $log,
    ) {}

    /**
     * @throws UzqrPaymentRefused
     */
    public function handle(Order $order, ?User $actor = null, bool $interactive = true): UzqrTransaction
    {
        [$transaction, $created] = DB::transaction(function () use ($order, $actor): array {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            $open = UzqrTransaction::query()->where('order_id', $order->id)->holding()->latest('id')->lockForUpdate()->first();

            if ($open instanceof UzqrTransaction) {
                return [$open, false];
            }

            $mode = $this->assertCanStart($order);

            $transaction = UzqrTransaction::query()->create([
                'mode' => $mode,
                'order_id' => $order->id,
                'amount' => $order->total,
                'status' => UzqrTransactionStatus::Creating,
                'next_check_at' => now()->addSeconds((int) config('uzqr.creating_timeout_seconds', 120)),
                'created_by_id' => $actor?->id,
            ]);

            $transaction->forceFill(['external_id' => UzqrTransaction::externalIdFor($transaction->id, $mode)])->save();

            return [$transaction, true];
        });

        if ($transaction->status === UzqrTransactionStatus::Creating && $transaction->invoice_id === null && ($created || $this->retryDue($transaction))) {
            $lock = Cache::lock('uzqr:create:'.$transaction->id, 30);

            if ($lock->get()) {
                try {
                    $this->issue($transaction, $actor, $interactive);
                } finally {
                    $lock->release();
                }
            }
        }

        return $transaction->fresh() ?? $transaction;
    }

    /**
     * @throws UzqrPaymentRefused
     */
    protected function assertCanStart(Order $order): PaymentMode
    {
        if ($order->payment_method !== PaymentMethod::UzqrDynamic) {
            throw new UzqrPaymentRefused('method');
        }

        if ($order->status === OrderStatus::Cancelled || $order->payment_status !== PaymentStatus::Unpaid || $order->hasActiveTransaction()) {
            throw new UzqrPaymentRefused('not_payable');
        }

        $mode = $order->is_test ? PaymentMode::Test : PaymentMode::Prod;

        if (! $this->config->canStart($mode) || ! $this->paymentOptions->allows(PaymentMethod::UzqrDynamic, $order->paymentContext())) {
            throw new UzqrPaymentRefused('unavailable');
        }

        $rows = UzqrTransaction::query()->where('order_id', $order->id);

        if ((clone $rows)->where('created_at', '>=', now(Lead::TIMEZONE)->startOfDay()->utc())->count() >= (int) config('uzqr.limits.per_order_day', 5)
            || (clone $rows)->where('created_at', '>=', now()->subHour())->count() >= (int) config('uzqr.limits.per_order_hour', 3)) {
            throw new UzqrPaymentRefused('order_cap');
        }

        return $mode;
    }

    /**
     * A transiently failed create may be retried (same external_id) for 2 minutes.
     */
    protected function retryDue(UzqrTransaction $transaction): bool
    {
        return $transaction->created_at->greaterThan(now()->subSeconds((int) config('uzqr.creating_timeout_seconds', 120)))
            && $transaction->updated_at->lessThanOrEqualTo(now()->subSeconds(self::RETRY_AFTER_SECONDS));
    }

    protected function issue(UzqrTransaction $transaction, ?User $actor, bool $interactive): void
    {
        try {
            $invoice = $this->client->forMode($transaction->mode, $interactive)->createInvoice((string) $transaction->external_id, $transaction->amount);
        } catch (UzqrException $exception) {
            $this->failed($transaction, $exception);

            return;
        }

        $this->config->clearAuthFailed($transaction->mode);

        if (! UzqrQrText::matchesAmount($invoice->qrText, $transaction->amount)) {
            $this->closeCreating($transaction, 'qr_amount_mismatch', null);
            $this->alerts->problem('qr_amount_mismatch', $transaction->mode, $transaction->order);

            try {
                // Best effort: the QR was never shown, but don't leave it payable.
                $this->client->forMode($transaction->mode, $interactive)->cancelInvoice($invoice->invoiceId);
            } catch (UzqrException) {
                // Logged by the client.
            }

            return;
        }

        try {
            DB::transaction(function () use ($transaction, $invoice, $actor): void {
                [$order, $locked] = $this->lockPair($transaction);

                if ($locked->status !== UzqrTransactionStatus::Creating || $locked->invoice_id !== null) {
                    return;
                }

                $locked->forceFill([
                    'invoice_id' => $invoice->invoiceId,
                    'qr_text' => $invoice->qrText,
                    'status' => UzqrTransactionStatus::Pending,
                    'remote_status' => 1,
                    'expires_at' => $locked->created_at->copy()->addMinutes((int) config('uzqr.invoice_minutes', 24 * 60 - 2)),
                    'next_check_at' => now()->addMinute(),
                    'last_error' => null,
                    'last_error_code' => null,
                ])->save();

                if ($order->payment_status === PaymentStatus::Unpaid) {
                    $order->forceFill(['payment_status' => PaymentStatus::Pending, 'payment_pending_at' => now()])->save();
                }

                $this->log->handle($order, 'uzqr_created', $actor, ['transaction_id' => $locked->id, 'amount' => $locked->amount], ActivityType::Payment);
            });
        } catch (UniqueConstraintViolationException) {
            // The bank returned an invoice_id another row already has.
            $this->closeCreating($transaction, 'duplicate_invoice', null);
            $this->alerts->problem('duplicate_invoice', $transaction->mode, $transaction->order);
        }
    }

    protected function failed(UzqrTransaction $transaction, UzqrException $exception): void
    {
        if ($exception->isAuth()) {
            $this->config->flagAuthFailed($transaction->mode);
            $this->alerts->problem($exception->reason, $transaction->mode);
        }

        if ($exception->isTransient()) {
            UzqrTransaction::query()->whereKey($transaction->id)->where('status', UzqrTransactionStatus::Creating->value)->update([
                'last_error' => $exception->reason,
                'last_error_code' => $exception->errorCode,
                'check_failures' => DB::raw('check_failures + 1'),
                'updated_at' => now(),
            ]);

            return;
        }

        $this->closeCreating($transaction, $exception->reason, $exception->errorCode);
    }

    /**
     * creating → create_failed (final; nothing was shown to the customer).
     */
    public function closeCreating(UzqrTransaction $transaction, string $reason, ?int $errorCode): void
    {
        DB::transaction(function () use ($transaction, $reason, $errorCode): void {
            [$order, $locked] = $this->lockPair($transaction);

            if ($locked->status !== UzqrTransactionStatus::Creating || $locked->invoice_id !== null) {
                return;
            }

            $locked->forceFill([
                'status' => UzqrTransactionStatus::CreateFailed,
                'last_error' => $reason,
                'last_error_code' => $errorCode,
                'closed_at' => now(),
                'next_check_at' => null,
            ])->save();

            $this->releaseOrder($order);
        });
    }
}
