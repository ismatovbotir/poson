<?php

namespace App\Actions\Payments\Uzqr;

use App\Enums\UzqrTransactionStatus;
use App\Models\UzqrTransaction;
use App\Services\Payments\Uzqr\UzqrClient;
use Illuminate\Support\Facades\RateLimiter;

/**
 * uzqr:sync (every minute, plan 11e §2, conditions 38, 39, 44): closes
 * "creating" rows older than 2 minutes (create_failed), then checks up to
 * 100 holding invoices (and released holds without a bank-final status,
 * condition 61) whose next_check_at is due, oldest first, with the
 * background timeouts — buyers close the tab after paying.
 */
class SweepUzqrInvoices
{
    public function __construct(protected RefreshUzqrInvoice $refresh, protected StartUzqrPayment $start) {}

    /**
     * @return int invoices checked
     */
    public function handle(): int
    {
        UzqrTransaction::query()
            ->where('status', UzqrTransactionStatus::Creating->value)
            ->whereNull('invoice_id')
            ->where('created_at', '<=', now()->subSeconds((int) config('uzqr.creating_timeout_seconds', 120)))
            ->limit((int) config('uzqr.sweep_batch', 100))
            ->get()
            ->each(fn (UzqrTransaction $transaction) => $this->start->closeCreating($transaction, 'create_timeout', null));

        // Released holds past their re-check window: no further automatic checks (condition 61, review #1).
        UzqrTransaction::query()
            ->releasedOpen()
            ->whereNotNull('next_check_at')
            ->where('resolved_at', '<=', now()->subDays((int) config('uzqr.released_check_days', 30)))
            ->update(['next_check_at' => null, 'updated_at' => now()]);

        $due = UzqrTransaction::query()
            ->with('order')
            ->awaitingBank()
            ->whereNotNull('invoice_id')
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', now())
            ->orderBy('next_check_at')
            ->limit((int) config('uzqr.sweep_batch', 100))
            ->get();

        foreach ($due as $transaction) {
            $this->waitForUpstreamSlot();
            $this->refresh->handle($transaction, 'sweep');
        }

        return $due->count();
    }

    /**
     * Keep the global upstream cap (N calls per second) by waiting up to 2 s.
     */
    protected function waitForUpstreamSlot(): void
    {
        $perSecond = (int) config('uzqr.limits.upstream_per_second', 5);

        for ($i = 0; $i < 8 && RateLimiter::tooManyAttempts(UzqrClient::UPSTREAM_KEY, $perSecond); $i++) {
            usleep(250_000);
        }
    }
}
