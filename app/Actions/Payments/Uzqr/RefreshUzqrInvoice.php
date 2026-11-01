<?php

namespace App\Actions\Payments\Uzqr;

use App\Actions\Orders\LogOrderActivity;
use App\Actions\Payments\Uzqr\Concerns\LocksUzqrTransaction;
use App\Enums\ActivityType;
use App\Enums\UzqrTransactionStatus;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrAlerts;
use App\Services\Payments\Uzqr\UzqrClient;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Services\Payments\Uzqr\UzqrException;
use App\Services\Payments\Uzqr\UzqrInvoiceStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ask the bank for an invoice's status (plan 11e §3, conditions 36, 39, 44,
 * 52, 53): from the modal poll, uzqr:sync and the admin "Check status now",
 * always with the row's own mode credentials and base URL.
 *
 * - coalesced: at most one upstream call per invoice per 3 s (a
 *   non-blocking cache lock; otherwise the DB state is returned);
 * - status 1: next check by age (< 15 min 1 min, < 2 h 5 min, then 30 min,
 *   never past the expiry). At expiry the invoice is cancelled at the bank;
 *   still 1 at expiry + 5 min → "expired" but still holding the order,
 *   finance alerted, re-checked after 1 h, then daily until expires_at +
 *   released_check_days (never auto-released);
 * - status 2 → SettleUzqrPayment; status 3 / error 3001 → final (failed, or
 *   expired once past the expiry) and the order back to unpaid if nothing
 *   else holds it;
 * - 1001 / 1002 → auth-failed flag (hides the method) + hourly alert;
 *   429 / 5xx / network → backoff, never an inline retry;
 * - a released hold (Admin, conditions 57–61) is re-checked daily: 2
 *   settles it (or unmatched), 3 / 3001 closes it; the order is not held.
 */
class RefreshUzqrInvoice
{
    use LocksUzqrTransaction;

    public const CONTEXTS = ['poll', 'sweep', 'admin'];

    public function __construct(
        protected UzqrClient $client,
        protected UzqrConfig $config,
        protected SettleUzqrPayment $settle,
        protected StartUzqrPayment $start,
        protected UzqrAlerts $alerts,
        protected LogOrderActivity $log,
    ) {}

    public function handle(UzqrTransaction $transaction, string $context = 'poll', bool $coalesce = true): UzqrTransaction
    {
        // Keep an eager-loaded order (sweep) for alerts; state changes re-read it under the lock.
        $transaction = $transaction->fresh()?->setRelations($transaction->getRelations()) ?? $transaction;

        if ($transaction->status === UzqrTransactionStatus::Creating && $transaction->invoice_id === null) {
            if ($transaction->created_at->lessThanOrEqualTo(now()->subSeconds((int) config('uzqr.creating_timeout_seconds', 120)))) {
                $this->start->closeCreating($transaction, 'create_timeout', null);
            }

            return $transaction->fresh() ?? $transaction;
        }

        if (! $transaction->isCheckable()) {
            return $transaction;
        }

        if ($coalesce && ! Cache::lock('uzqr:poll:'.$transaction->id, 3)->get()) {
            return $transaction;
        }

        $client = $this->client->forMode($transaction->mode, $context !== 'sweep');
        $invoiceId = (string) $transaction->invoice_id;

        UzqrTransaction::query()->whereKey($transaction->id)->update(['last_checked_at' => now()]);

        if ($transaction->status === UzqrTransactionStatus::Pending && $transaction->expires_at !== null && $transaction->expires_at->isPast() && $transaction->cancel_requested_at === null) {
            try {
                $client->cancelInvoice($invoiceId);
                UzqrTransaction::query()->whereKey($transaction->id)->update(['cancel_requested_at' => now()]);
            } catch (UzqrException $exception) {
                if (! in_array($exception->reason, [UzqrException::ALREADY_PAID, UzqrException::NOT_FOUND], true)) {
                    return $this->failure($transaction, $exception);
                }
            }
        }

        try {
            $status = $client->invoiceStatus($invoiceId);
        } catch (UzqrException $exception) {
            return $this->failure($transaction, $exception);
        }

        $this->config->clearAuthFailed($transaction->mode);

        return match ($status->status) {
            UzqrInvoiceStatus::PAID => $this->settle->handle($transaction, $status),
            UzqrInvoiceStatus::REJECTED => $this->close($transaction, 3),
            default => $this->stillOpen($transaction),
        };
    }

    /**
     * Bank-confirmed final status without payment (3 or 3001).
     */
    public function close(UzqrTransaction $transaction, ?int $remoteStatus): UzqrTransaction
    {
        return DB::transaction(function () use ($transaction, $remoteStatus): UzqrTransaction {
            [$order, $locked] = $this->lockPair($transaction);

            if ($locked->isReleasedOpen()) {
                // A released hold reaches its bank-confirmed end: stop checking (condition 61).
                $locked->forceFill(['remote_status' => $remoteStatus, 'closed_at' => now(), 'next_check_at' => null])->save();
                $this->log->handle($order, 'uzqr_closed', null, ['transaction_id' => $locked->id, 'amount' => $locked->amount, 'status' => $locked->status->value], ActivityType::Payment);

                return $locked;
            }

            if (! $locked->holdsOrder()) {
                return $locked;
            }

            $expired = $locked->expires_at !== null && $locked->expires_at->isPast();

            $locked->forceFill([
                'status' => $expired ? UzqrTransactionStatus::Expired : UzqrTransactionStatus::Failed,
                'remote_status' => $remoteStatus,
                'closed_at' => now(),
                'next_check_at' => null,
                'last_error' => $remoteStatus === null ? UzqrException::NOT_FOUND : null,
                'last_error_code' => $remoteStatus === null ? 3001 : null,
            ])->save();

            $this->releaseOrder($order);

            $this->log->handle($order, 'uzqr_closed', null, ['transaction_id' => $locked->id, 'amount' => $locked->amount, 'status' => $locked->status->value], ActivityType::Payment);

            return $locked;
        });
    }

    protected function stillOpen(UzqrTransaction $transaction): UzqrTransaction
    {
        return DB::transaction(function () use ($transaction): UzqrTransaction {
            [$order, $locked] = $this->lockPair($transaction);

            if ($locked->isReleasedOpen()) {
                // Released hold, bank still 1: re-check daily for released_check_days (condition 61).
                $locked->forceFill(['remote_status' => 1, 'check_failures' => 0, 'last_error' => null, 'last_error_code' => null, 'next_check_at' => static::withinWindow($locked, now()->addDay())])->save();

                return $locked;
            }

            if (! $locked->holdsOrder()) {
                return $locked;
            }

            $values = ['remote_status' => 1, 'check_failures' => 0, 'last_error' => null, 'last_error_code' => null];
            $expiresAt = $locked->expires_at;

            if ($expiresAt === null || $expiresAt->isFuture()) {
                $values['next_check_at'] = static::nextCheck($locked->created_at, $expiresAt);
            } elseif (now()->lessThan($expiresAt->copy()->addMinutes(5))) {
                $values['next_check_at'] = $expiresAt->copy()->addMinutes(5);
            } else {
                // Still payable at the bank after expiry + 5 min: keep the hold, alert, re-check after 1 h,
                // then daily while now < expires_at + released_check_days (M-a: the order stays held).
                $first = $locked->status !== UzqrTransactionStatus::Expired;
                $until = $expiresAt->copy()->addDays((int) config('uzqr.released_check_days', 30));
                $values['status'] = UzqrTransactionStatus::Expired;
                $values['next_check_at'] = match (true) {
                    $first => now()->addHour(),
                    now()->lessThan($until) => now()->addDay(),
                    default => null,
                };

                if ($locked->alerted_at === null) {
                    $values['alerted_at'] = now();
                    $this->alerts->problem('expired_unconfirmed', $locked->mode, $order);
                }
            }

            $locked->forceFill($values)->save();

            return $locked;
        });
    }

    protected function failure(UzqrTransaction $transaction, UzqrException $exception): UzqrTransaction
    {
        if ($exception->reason === UzqrException::NOT_FOUND) {
            return $this->close($transaction, null);
        }

        if ($exception->isAuth()) {
            $this->config->flagAuthFailed($transaction->mode);
            $this->alerts->problem($exception->reason, $transaction->mode);
        } elseif (in_array($exception->reason, [UzqrException::MISSING_PAY_ID, UzqrException::INVALID_RESPONSE], true)) {
            $this->alerts->problem($exception->reason, $transaction->mode, $transaction->order);
        }

        if ($exception->reason === UzqrException::BUSY) {
            return $transaction;
        }

        $failures = $transaction->check_failures + 1;
        $delay = match (true) {
            $exception->isAuth() => 15,
            $exception->reason === UzqrException::RATE_LIMITED => min(30, 2 * $failures),
            default => min(30, $failures),
        };

        UzqrTransaction::query()->whereKey($transaction->id)->update([
            'check_failures' => $failures,
            'last_error' => $exception->reason,
            'last_error_code' => $exception->errorCode,
            'next_check_at' => static::withinWindow($transaction, now()->addMinutes($delay)),
            'updated_at' => now(),
        ]);

        return $transaction->fresh() ?? $transaction;
    }

    /**
     * $next, or null once the row is past its re-check window (released hold / expired holding row, sec L3).
     */
    protected static function withinWindow(UzqrTransaction $transaction, CarbonInterface $next): ?CarbonInterface
    {
        $until = $transaction->checkUntil();

        return $until === null || now()->lessThan($until) ? $next : null;
    }

    /**
     * Backoff by age: < 15 min → 1 min, < 2 h → 5 min, else 30 min; never past the expiry.
     */
    public static function nextCheck(CarbonInterface $createdAt, ?CarbonInterface $expiresAt): CarbonInterface
    {
        $age = $createdAt->diffInMinutes(now(), true);
        $next = now()->addMinutes(match (true) {
            $age < 15 => 1,
            $age < 120 => 5,
            default => 30,
        });

        return $expiresAt !== null && $next->greaterThan($expiresAt) ? $expiresAt->copy() : $next;
    }
}
