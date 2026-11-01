<?php

namespace App\Actions\Payments\Uzqr;

use App\Actions\Orders\LogOrderActivity;
use App\Actions\Payments\SettleOnlinePayment;
use App\Actions\Payments\Uzqr\Concerns\LocksUzqrTransaction;
use App\Enums\ActivityType;
use App\Enums\PaymentMethod;
use App\Enums\UzqrSettlement;
use App\Events\Uzqr\UzqrUnmatchedResolved;
use App\Models\User;
use App\Models\UzqrTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An Admin decides what happens to an unmatched UzQR payment (condition
 * 50; authorized by the caller with OrderPolicy::resolveUzqr):
 *
 * - applied: only when the order can take it now (same checks as the
 *   settle: method uzqr_dynamic, unpaid / pending, exact amount, mode, no
 *   other paid goods row) → the normal SettleOnlinePayment;
 * - refunded_externally: the money went back through the UzQR cabinet /
 *   bank; a reference is required; a pending order goes back to unpaid.
 *
 * Reason stored, logged in the order history, finance + sales alerted.
 */
class ResolveUzqrUnmatched
{
    use LocksUzqrTransaction;

    public const ACTIONS = [UzqrTransaction::RESOLUTION_APPLIED, UzqrTransaction::RESOLUTION_REFUNDED];

    public const REFERENCE_MAX = 100;

    public const NOTE_MAX = 500;

    public function __construct(protected SettleOnlinePayment $settle, protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(UzqrTransaction $transaction, string $action, ?string $reference, string $note, User $actor): UzqrTransaction
    {
        $reference = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $reference));
        $note = trim($note);

        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['resolution' => __('uzqr.errors.resolution_invalid')]);
        }

        if ($note === '') {
            throw ValidationException::withMessages(['resolutionNote' => __('orders.errors.reason_required')]);
        }

        if ($action === UzqrTransaction::RESOLUTION_REFUNDED && $reference === '') {
            throw ValidationException::withMessages(['resolutionReference' => __('orders.errors.reference_required')]);
        }

        // Too long is refused, never silently cut (change review L5).
        if (mb_strlen($reference) > self::REFERENCE_MAX) {
            throw ValidationException::withMessages(['resolutionReference' => __('validation.max.string', ['attribute' => __('uzqr.admin.reference'), 'max' => self::REFERENCE_MAX])]);
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['resolutionNote' => __('validation.max.string', ['attribute' => __('uzqr.admin.note'), 'max' => self::NOTE_MAX])]);
        }

        $transaction = DB::transaction(function () use ($transaction, $action, $reference, $note, $actor): UzqrTransaction {
            [$order, $locked] = $this->lockPair($transaction);

            if ($locked->settlement !== UzqrSettlement::Unmatched || $locked->resolved_at !== null) {
                throw ValidationException::withMessages(['resolution' => __('uzqr.errors.not_unmatched')]);
            }

            if ($action === UzqrTransaction::RESOLUTION_APPLIED) {
                if ($locked->pay_id === null || SettleUzqrPayment::mismatch($order, $locked) !== null) {
                    throw ValidationException::withMessages(['resolution' => __('uzqr.errors.cannot_apply')]);
                }

                $this->settle->handle($order, PaymentMethod::UzqrDynamic, UzqrTransaction::PROVIDER, $locked->pay_id, $locked->amount);
            } else {
                $this->releaseOrder($order);
            }

            $locked->forceFill([
                'resolution' => $action,
                'resolution_reference' => $reference !== '' ? $reference : null,
                'resolution_note' => $note,
                'resolved_at' => now(),
                'resolved_by_id' => $actor->id,
                'settlement' => $action === UzqrTransaction::RESOLUTION_APPLIED ? UzqrSettlement::Settled : UzqrSettlement::Unmatched,
            ])->save();

            $this->log->handle($order, 'uzqr_resolved', $actor, ['transaction_id' => $locked->id, 'amount' => $locked->amount, 'resolution' => $action], ActivityType::Payment, $note);

            return $locked;
        });

        UzqrUnmatchedResolved::dispatch($transaction, $actor);

        return $transaction;
    }
}
