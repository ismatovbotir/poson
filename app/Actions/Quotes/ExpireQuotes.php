<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Models\Lead;
use App\Models\Quote;
use Illuminate\Validation\ValidationException;

/**
 * Nightly (routes/console.php): sent quotes whose valid_until is before
 * today (Tashkent) become expired, through ChangeQuoteStatus with no actor
 * (the system), so each gets its history entry on the quote, customer and
 * lead. Drafts are left alone (they were never offered).
 */
class ExpireQuotes
{
    public function __construct(protected ChangeQuoteStatus $changeStatus) {}

    /**
     * @return int Quotes expired
     */
    public function handle(): int
    {
        $today = now(Lead::TIMEZONE)->toDateString();
        $expired = 0;

        Quote::query()
            ->where('status', QuoteStatus::Sent->value)
            ->whereDate('valid_until', '<', $today)
            ->orderBy('id')
            ->chunkById(100, function ($quotes) use (&$expired): void {
                foreach ($quotes as $quote) {
                    try {
                        $this->changeStatus->handle($quote, QuoteStatus::Expired, null);
                        $expired++;
                    } catch (ValidationException) {
                        // Accepted / rejected meanwhile: nothing to do.
                    }
                }
            });

        return $expired;
    }
}
