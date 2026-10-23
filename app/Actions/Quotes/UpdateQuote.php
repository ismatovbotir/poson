<?php

namespace App\Actions\Quotes;

use App\Data\Quotes\QuoteData;
use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit a draft or sent quote (plan 06): lines, validity, notes, locale.
 * The customer, lead, number and price level stay. Components authorize
 * first (QuotePolicy::update); the status is re-checked under a row lock.
 *
 * Changing the locale keeps the existing lines' name snapshots (only new
 * product lines are named in the new language). Editing a quote the
 * customer may already have (sent) writes an "updated" history entry
 * with the old and new total.
 */
class UpdateQuote
{
    public function __construct(
        protected SaveQuoteItems $saveItems,
        protected LogQuoteActivity $log,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Quote $quote, QuoteData $data, ?User $actor = null): Quote
    {
        $validUntil = $data->validUntil === $quote->valid_until->toDateString()
            ? $quote->valid_until
            : CreateQuote::validUntil($data->validUntil);

        return DB::transaction(function () use ($quote, $data, $validUntil, $actor): Quote {
            $locked = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages(['status' => __('crm.quotes.errors.not_editable')]);
            }

            $oldTotal = (int) $locked->total;

            $quote->fill([
                'valid_until' => $validUntil->toDateString(),
                'notes' => filled($data->notes) ? mb_substr(trim((string) $data->notes), 0, 5000) : null,
                'locale' => in_array($data->locale, config('app.supported_locales'), true) ? $data->locale : $quote->locale,
            ])->save();

            $this->saveItems->handle($quote, $data->items ?? []);
            $quote->refresh();

            if ($locked->status !== QuoteStatus::Draft) {
                $this->log->handle($quote, 'updated', $actor, ['old_total' => $oldTotal, 'new_total' => $quote->total]);
            }

            return $quote;
        });
    }
}
