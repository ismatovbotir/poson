<?php

namespace App\Actions\Payments\Uzqr;

use App\Actions\Orders\LogOrderActivity;
use App\Actions\Payments\Uzqr\Concerns\LocksUzqrTransaction;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Enums\UzqrTransactionStatus;
use App\Events\Uzqr\UzqrHoldReleased;
use App\Models\User;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrAlerts;
use App\Services\Payments\Uzqr\UzqrClient;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Services\Payments\Uzqr\UzqrException;
use App\Services\Payments\Uzqr\UzqrInvoiceStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Release hold" of an expired UzQR invoice the bank never confirmed
 * (sign-off conditions 57–66; the caller authorizes OrderPolicy::releaseUzqrHold
 * and checks the recent password confirmation and the rate limit):
 *
 * 1. Admin role and a 1–500 character reason; the row must be releasable
 *    (UzqrTransaction::isReleasable()).
 * 2. Outside any lock, with the row's own mode: best-effort invoice/cancel,
 *    then invoice/status.
 *    - status 2 → SettleUzqrPayment (settled or unmatched), no release;
 *    - cancel 3002 without a usable paid status → refused, no release;
 *    - status 3 / 3001 → closed (bank-final), no release;
 *    - status 1, or a transient / auth error → release.
 * 3. Release under order → row lock, re-checking isReleasable(): status
 *    "released", resolution hold_released + who / when / note, the order
 *    back to unpaid when nothing else holds it, activity
 *    uzqr_hold_released, UzqrHoldReleased (finance + sales, after commit).
 *    The row stays checkable: a later status 2 settles or goes unmatched.
 */
class ReleaseUzqrHold
{
    use LocksUzqrTransaction;

    public const NOTE_MAX = 500;

    public function __construct(
        protected UzqrClient $client,
        protected UzqrConfig $config,
        protected RefreshUzqrInvoice $refresh,
        protected SettleUzqrPayment $settle,
        protected UzqrAlerts $alerts,
        protected LogOrderActivity $log,
    ) {}

    /**
     * @return string outcome: released | settled | unmatched | closed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(UzqrTransaction $transaction, string $note, User $actor): string
    {
        if (! $actor->hasRole(Role::Admin)) {
            throw new AuthorizationException;
        }

        $note = trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $note));

        if ($note === '') {
            throw ValidationException::withMessages(['releaseNote' => __('orders.errors.reason_required')]);
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['releaseNote' => __('validation.max.string', ['attribute' => __('uzqr.admin.note'), 'max' => self::NOTE_MAX])]);
        }

        $transaction = $transaction->fresh() ?? $transaction;

        if (! $transaction->isReleasable()) {
            throw ValidationException::withMessages(['releaseNote' => __('uzqr.errors.not_releasable')]);
        }

        $client = $this->client->forMode($transaction->mode, true);
        $invoiceId = (string) $transaction->invoice_id;
        $bankSaysPaid = false;

        try {
            $client->cancelInvoice($invoiceId);
            UzqrTransaction::query()->whereKey($transaction->id)->update(['cancel_requested_at' => now()]);
        } catch (UzqrException $exception) {
            if ($exception->reason === UzqrException::ALREADY_PAID) {
                $bankSaysPaid = true;
            } elseif ($exception->reason === UzqrException::NOT_FOUND) {
                return $this->stopped($this->refresh->close($transaction, null), 'closed', $note, $actor);
            } elseif ($exception->isAuth()) {
                $this->authFailed($transaction, $exception);
            }
        }

        $bank = 'still_open';

        try {
            $status = $client->invoiceStatus($invoiceId);
            UzqrTransaction::query()->whereKey($transaction->id)->update(['last_checked_at' => now()]);
        } catch (UzqrException $exception) {
            $status = null;

            if ($exception->reason === UzqrException::NOT_FOUND && ! $bankSaysPaid) {
                return $this->stopped($this->refresh->close($transaction, null), 'closed', $note, $actor);
            }

            if ($bankSaysPaid || $exception->reason === UzqrException::MISSING_PAY_ID) {
                if ($exception->reason === UzqrException::MISSING_PAY_ID) {
                    $this->alerts->problem($exception->reason, $transaction->mode, $transaction->order);
                }

                throw ValidationException::withMessages(['releaseNote' => __('uzqr.errors.release_bank_paid')]);
            }

            if ($exception->isAuth()) {
                $this->authFailed($transaction, $exception);
            }

            $bank = $exception->isAuth() ? 'auth_error' : 'bank_error';
        }

        if ($status !== null) {
            $this->config->clearAuthFailed($transaction->mode);

            if ($status->status === UzqrInvoiceStatus::PAID) {
                $settled = $this->settle->handle($transaction, $status);

                return $this->stopped($settled, $settled->settlement?->value ?? 'settled', $note, $actor);
            }

            if ($status->status === UzqrInvoiceStatus::REJECTED) {
                return $this->stopped($this->refresh->close($transaction, UzqrInvoiceStatus::REJECTED), 'closed', $note, $actor);
            }

            if ($bankSaysPaid) {
                throw ValidationException::withMessages(['releaseNote' => __('uzqr.errors.release_bank_paid')]);
            }
        }

        DB::transaction(function () use ($transaction, $note, $actor, $bank): void {
            [$order, $locked] = $this->lockPair($transaction);

            if (! $locked->isReleasable()) {
                throw ValidationException::withMessages(['releaseNote' => __('uzqr.errors.not_releasable')]);
            }

            $locked->forceFill([
                'status' => UzqrTransactionStatus::Released,
                'resolution' => UzqrTransaction::RESOLUTION_HOLD_RELEASED,
                'resolution_note' => $note,
                'resolved_at' => now(),
                'resolved_by_id' => $actor->id,
                'next_check_at' => now()->addDay(),
            ])->save();

            $this->releaseOrder($order);

            $this->log->handle($order, 'uzqr_hold_released', $actor, [
                'transaction_id' => $locked->id,
                'amount' => $locked->amount,
                'bank' => $bank,
            ], ActivityType::Payment, $note);

            UzqrHoldReleased::dispatch($locked, $actor);
        });

        return 'released';
    }

    /**
     * The bank answered with a final status during the release: recorded, nothing released.
     */
    protected function stopped(UzqrTransaction $transaction, string $outcome, string $note, User $actor): string
    {
        if ($transaction->order !== null) {
            $this->log->handle($transaction->order, 'uzqr_hold_release_stopped', $actor, [
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
                'bank' => $outcome,
            ], ActivityType::Payment, $note);
        }

        return $outcome;
    }

    protected function authFailed(UzqrTransaction $transaction, UzqrException $exception): void
    {
        $this->config->flagAuthFailed($transaction->mode);
        $this->alerts->problem($exception->reason, $transaction->mode);
    }
}
