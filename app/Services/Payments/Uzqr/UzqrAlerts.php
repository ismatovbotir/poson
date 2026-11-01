<?php

namespace App\Services\Payments\Uzqr;

use App\Enums\PaymentMode;
use App\Events\Uzqr\UzqrIntegrationProblem;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Finance alerts about UzQR problems, at most one per problem, mode (and
 * order) per hour (condition 53).
 */
class UzqrAlerts
{
    public const PROBLEMS = [
        'auth_failed', 'blocked', 'missing_pay_id', 'invalid_response', 'expired_unconfirmed',
        'qr_amount_mismatch', 'duplicate_invoice', 'create_failed', 'still_open',
    ];

    /**
     * Inside a DB transaction the dedupe key is taken only after the commit
     * (change review L4): a rolled-back transaction must not swallow the
     * alert for an hour. Returns false when deduped; true when sent or
     * deferred to the commit.
     */
    public function problem(string $problem, PaymentMode $mode, ?Order $order = null): bool
    {
        $key = 'uzqr:alert:'.$problem.':'.$mode->value.($order !== null ? ':'.$order->id : '');
        $orderNumber = $order?->number;
        $orderId = $order?->id;
        $sent = true;

        DB::afterCommit(function () use ($key, $problem, $mode, $orderNumber, $orderId, &$sent): void {
            if (! Cache::add($key, true, 3600)) {
                $sent = false;

                return;
            }

            UzqrIntegrationProblem::dispatch($problem, $mode, $orderNumber, $orderId);
        });

        return $sent;
    }
}
