<?php

namespace App\Actions\Payments\Uzqr;

use App\Actions\Orders\LogOrderActivity;
use App\Actions\Payments\SettleOnlinePayment;
use App\Actions\Payments\Uzqr\Concerns\LocksUzqrTransaction;
use App\Enums\ActivityType;
use App\Enums\OrderPaymentPurpose;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\UzqrSettlement;
use App\Enums\UzqrTransactionStatus;
use App\Events\Uzqr\UzqrPaymentUnmatched;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrInvoiceStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bank status 2 for a UzQR invoice (plan 11e §3, conditions 36, 42, 50, 52):
 * idempotent, under the order lock → row lock. Stores pay_id, card type and
 * the last 4 card digits (never the phone), then verifies the order can take
 * the money: method uzqr_dynamic, unpaid / pending and not cancelled, the
 * created amount equals the current total (the bank sends no amount), the
 * row's mode matches the order (TEST ↔ test), no other paid goods row and
 * no other row / ledger entry with this pay_id.
 *
 * Pass → SettleOnlinePayment (ledger row provider uzqr, provider_ref =
 * pay_id, order paid, OrderPaid once after commit). Fail → "unmatched" with
 * a reason, the order untouched, finance + sales alerted; only an Admin
 * resolves it (ResolveUzqrUnmatched).
 */
class SettleUzqrPayment
{
    use LocksUzqrTransaction;

    public function __construct(protected SettleOnlinePayment $settle, protected LogOrderActivity $log) {}

    public function handle(UzqrTransaction $transaction, UzqrInvoiceStatus $status): UzqrTransaction
    {
        if ($status->status !== UzqrInvoiceStatus::PAID || $status->payId === null || $transaction->invoice_id === null || ! hash_equals($transaction->invoice_id, $status->invoiceId)) {
            throw new InvalidArgumentException('Only a paid status of this invoice can be settled.');
        }

        return DB::transaction(function () use ($transaction, $status): UzqrTransaction {
            [$order, $transaction] = $this->lockPair($transaction);

            if ($transaction->status === UzqrTransactionStatus::Paid) {
                return $transaction;
            }

            $duplicate = UzqrTransaction::query()->where('mode', $transaction->mode->value)->where('pay_id', $status->payId)->whereKeyNot($transaction->id)->exists()
                || OrderPayment::query()->where('provider', UzqrTransaction::PROVIDER)->where('provider_ref', $status->payId)->exists();

            $reason = $duplicate ? 'duplicate_pay_id' : static::mismatch($order, $transaction);

            if ($transaction->status === UzqrTransactionStatus::Released) {
                // The bank took the money after an Admin released the hold: the release is superseded
                // (kept in the order history), so an unmatched payment is open for an Admin decision again.
                // Who released it and when is recorded before the fields are cleared (sec L2).
                $this->log->handle($order, 'uzqr_release_superseded', null, [
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'released_by_id' => $transaction->resolved_by_id,
                    'released_at' => $transaction->resolved_at?->toIso8601String(),
                    'reason' => $reason,
                ], ActivityType::Payment);

                $transaction->forceFill(['resolution' => null, 'resolution_note' => null, 'resolved_at' => null, 'resolved_by_id' => null]);
            }

            $transaction->forceFill([
                'status' => UzqrTransactionStatus::Paid,
                'remote_status' => UzqrInvoiceStatus::PAID,
                'pay_id' => $duplicate ? null : $status->payId,
                'card_type' => $status->cardType,
                'card_last4' => $status->cardLast4,
                'paid_at' => now(),
                'closed_at' => now(),
                'next_check_at' => null,
                'last_error' => null,
                'last_error_code' => null,
                'settlement' => $reason === null ? UzqrSettlement::Settled : UzqrSettlement::Unmatched,
                'unmatched_reason' => $reason,
            ])->save();

            if ($reason === null) {
                $this->settle->handle($order, PaymentMethod::UzqrDynamic, UzqrTransaction::PROVIDER, $status->payId, $transaction->amount);

                return $transaction;
            }

            $this->log->handle($order, 'uzqr_unmatched', null, [
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
                'reason' => $reason,
            ], ActivityType::Payment);

            UzqrPaymentUnmatched::dispatch($transaction);

            return $transaction;
        });
    }

    /**
     * Why the locked order can't take this row's money, or null when it can.
     */
    public static function mismatch(Order $order, UzqrTransaction $transaction): ?string
    {
        return match (true) {
            $order->payment_method !== PaymentMethod::UzqrDynamic => 'method_changed',
            $order->status === OrderStatus::Cancelled => 'order_cancelled',
            ! in_array($order->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Pending], true) => 'not_payable',
            $transaction->amount !== $order->total => 'amount_mismatch',
            ($transaction->mode === PaymentMode::Test) !== $order->is_test => 'mode_mismatch',
            OrderPayment::query()->where('order_id', $order->id)->where('purpose', OrderPaymentPurpose::Goods->value)->where('status', OrderPaymentStatus::Paid->value)->exists() => 'already_paid',
            default => null,
        };
    }
}
