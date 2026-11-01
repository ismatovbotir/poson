<?php

namespace App\Actions\Payments\Uzqr;

use App\Actions\Orders\LogOrderActivity;
use App\Enums\ActivityType;
use App\Models\User;
use App\Models\UzqrTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finance ticks off the "fiscal receipt required" task of a settled UzQR
 * payment (condition 47: receipts are issued outside the site until the
 * bank answers B5); payments.reconcile, authorized by the caller. Logged.
 */
class ConfirmUzqrFiscalReceipt
{
    public function __construct(protected LogOrderActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(UzqrTransaction $transaction, User $actor): void
    {
        DB::transaction(function () use ($transaction, $actor): void {
            $locked = UzqrTransaction::query()->whereKey($transaction->id)->receiptRequired()->lockForUpdate()->first();

            if (! $locked instanceof UzqrTransaction) {
                throw ValidationException::withMessages(['receipt' => __('uzqr.errors.receipt_not_required')]);
            }

            $locked->forceFill(['receipt_confirmed_at' => now(), 'receipt_confirmed_by_id' => $actor->id])->save();

            if ($locked->order !== null) {
                $this->log->handle($locked->order, 'uzqr_receipt_confirmed', $actor, ['transaction_id' => $locked->id, 'amount' => $locked->amount], ActivityType::Payment);
            }
        });
    }
}
