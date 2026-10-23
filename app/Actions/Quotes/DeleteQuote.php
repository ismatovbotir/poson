<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delete a draft quote (sent quotes are kept as history). Its own history
 * and lines go; the customer and lead keep a "deleted" entry. The number is
 * not reused.
 */
class DeleteQuote
{
    public function __construct(protected LogQuoteActivity $log) {}

    /**
     * @throws ValidationException
     */
    public function handle(Quote $quote, User $actor): void
    {
        DB::transaction(function () use ($quote, $actor): void {
            $status = Quote::query()->whereKey($quote->id)->lockForUpdate()->value('status');

            if (($status instanceof QuoteStatus ? $status : QuoteStatus::tryFrom((string) $status)) !== QuoteStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('crm.quotes.errors.delete_draft_only')]);
            }

            $quote->activities()->delete();
            $quote->delete();

            $quote->exists = false;
            $this->log->handle($quote, 'deleted', $actor);
        });
    }
}
