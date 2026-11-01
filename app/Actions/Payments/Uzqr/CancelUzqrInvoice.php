<?php

namespace App\Actions\Payments\Uzqr;

use App\Actions\Orders\LogOrderActivity;
use App\Enums\ActivityType;
use App\Models\User;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrAlerts;
use App\Services\Payments\Uzqr\UzqrClient;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Services\Payments\Uzqr\UzqrException;

/**
 * Cancel an open UzQR invoice at the bank (condition 39): staff "Cancel QR"
 * and automatically before a new QR. invoice/cancel, then a status call:
 * the hold is released only on a bank-confirmed final status (3 / 3001);
 * 3002 "already paid" → the status call settles it. While the bank still
 * says 1 the row keeps holding the order and uzqr:sync re-checks it.
 * A cancel requested less than 60 s ago is not sent again (the status
 * call is then coalesced), so repeated clicks can't amplify bank calls.
 */
class CancelUzqrInvoice
{
    public const RESEND_AFTER_SECONDS = 60;

    public function __construct(
        protected UzqrClient $client,
        protected UzqrConfig $config,
        protected RefreshUzqrInvoice $refresh,
        protected UzqrAlerts $alerts,
        protected LogOrderActivity $log,
    ) {}

    /**
     * @throws UzqrPaymentRefused
     */
    public function handle(UzqrTransaction $transaction, ?User $actor = null): UzqrTransaction
    {
        $transaction = $transaction->fresh() ?? $transaction;

        if (! $transaction->isCheckable()) {
            return $transaction;
        }

        // A cancel sent in the last minute is not repeated; the status call is coalesced too (change review M3).
        $recent = $transaction->cancel_requested_at !== null
            && $transaction->cancel_requested_at->greaterThan(now()->subSeconds(self::RESEND_AFTER_SECONDS));

        if ($recent) {
            return $this->refresh->handle($transaction, 'admin');
        }

        try {
            $this->client->forMode($transaction->mode, true)->cancelInvoice((string) $transaction->invoice_id);
        } catch (UzqrException $exception) {
            if ($exception->isAuth()) {
                $this->config->flagAuthFailed($transaction->mode);
                $this->alerts->problem($exception->reason, $transaction->mode);

                throw new UzqrPaymentRefused('unavailable');
            }

            if (! in_array($exception->reason, [UzqrException::ALREADY_PAID, UzqrException::NOT_FOUND], true)) {
                throw new UzqrPaymentRefused('cancel_failed');
            }
        }

        UzqrTransaction::query()->whereKey($transaction->id)->update(['cancel_requested_at' => now(), 'next_check_at' => now()->addMinute()]);

        if ($actor !== null && $transaction->order !== null) {
            $this->log->handle($transaction->order, 'uzqr_cancel_requested', $actor, ['transaction_id' => $transaction->id, 'amount' => $transaction->amount], ActivityType::Payment);
        }

        return $this->refresh->handle($transaction, 'admin', coalesce: false);
    }
}
