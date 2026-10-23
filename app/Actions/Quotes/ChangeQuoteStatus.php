<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move a quote along QuoteStatus::canTransitionTo() under a row lock and
 * log it on the quote, the customer and the lead (plan 06). Sent sets
 * sent_at, accepted sets accepted_at. markSent() is the idempotent "the
 * customer got it" step (public link copied, PDF downloaded, or by hand).
 * A null actor is the system (the nightly ExpireQuotes).
 */
class ChangeQuoteStatus
{
    public const VIA = ['manual', 'link', 'pdf'];

    public function __construct(protected LogQuoteActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Quote $quote, QuoteStatus $to, ?User $actor, string $via = 'manual'): Quote
    {
        return DB::transaction(function () use ($quote, $to, $actor, $via): Quote {
            $from = Quote::query()->whereKey($quote->id)->lockForUpdate()->value('status');
            $from = $from instanceof QuoteStatus ? $from : QuoteStatus::from((string) $from);

            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => __('crm.quotes.errors.transition_invalid', ['from' => $from->label(), 'to' => $to->label()])]);
            }

            $quote->fill([
                'status' => $to,
                'status_changed_at' => now(),
                'sent_at' => $to === QuoteStatus::Sent ? now() : $quote->sent_at,
                'accepted_at' => $to === QuoteStatus::Accepted ? now() : $quote->accepted_at,
            ])->save();

            $this->log->handle($quote, $to->value, $actor, ['via' => $to === QuoteStatus::Sent && in_array($via, self::VIA, true) ? $via : null]);

            return $quote;
        });
    }

    /**
     * Draft → sent; a quote that is already past draft stays as it is.
     */
    public function markSent(Quote $quote, User $actor, string $via): Quote
    {
        if ($quote->status !== QuoteStatus::Draft) {
            return $quote;
        }

        try {
            return $this->handle($quote, QuoteStatus::Sent, $actor, $via);
        } catch (ValidationException) {
            // Changed meanwhile (sent or closed by someone else): nothing to do.
            return $quote->refresh();
        }
    }
}
