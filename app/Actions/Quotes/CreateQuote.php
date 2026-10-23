<?php

namespace App\Actions\Quotes;

use App\Data\Quotes\QuoteData;
use App\Data\Quotes\QuoteItemData;
use App\Enums\QuoteStatus;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Quote;
use App\Models\Sequence;
use App\Models\User;
use App\Support\Quotes\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create a draft quote (plan 06) for a customer, optionally from one of its
 * leads. Components authorize first (QuotePolicy::create).
 *
 *  - number Q-YYYY-NNNN from the sequences table ("quote-YYYY", Tashkent year);
 *  - ulid for the public link;
 *  - snapshots: the customer's price level and locale (or the given locale);
 *  - valid until: the given date, else 5 working days from today;
 *  - items: the given lines, or (from a lead, none given) the lead's items
 *    at the customer's level prices;
 *  - manager: the creating user;
 *  - a "created" history entry on the quote, the customer and the lead.
 */
class CreateQuote
{
    public function __construct(
        protected SaveQuoteItems $saveItems,
        protected LogQuoteActivity $log,
    ) {}

    /**
     * @param  array<string, mixed>  $logExtra  extra meta of the "created" entry (e.g. duplicated from)
     *
     * @throws ValidationException
     */
    public function handle(QuoteData $data, User $actor, array $logExtra = []): Quote
    {
        $customer = Customer::query()->find($data->customerId);

        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages(['customer_id' => __('crm.quotes.errors.customer_missing')]);
        }

        $lead = $data->leadId !== null ? Lead::query()->with('items')->find($data->leadId) : null;

        if ($data->leadId !== null && ($lead === null || $lead->customer_id !== $customer->id)) {
            throw ValidationException::withMessages(['lead_id' => __('crm.quotes.errors.lead_customer')]);
        }

        $validUntil = static::validUntil($data->validUntil);
        $locale = in_array($data->locale, config('app.supported_locales'), true) ? $data->locale : $customer->locale;

        // Up to 3 attempts: a deadlock on the sequence row is retried.
        return DB::transaction(function () use ($data, $actor, $customer, $lead, $validUntil, $locale, $logExtra): Quote {
            $year = now(Lead::TIMEZONE)->year;

            $quote = Quote::query()->create([
                'number' => static::formatNumber($year, Sequence::next("quote-{$year}")),
                'ulid' => (string) Str::ulid(),
                'customer_id' => $customer->id,
                'lead_id' => $lead?->id,
                'manager_id' => $actor->id,
                'created_by_id' => $actor->id,
                'status' => QuoteStatus::Draft,
                'price_level' => $customer->price_level,
                'locale' => in_array($locale, config('app.supported_locales'), true) ? $locale : config('app.locale'),
                'valid_until' => $validUntil->toDateString(),
                'notes' => filled($data->notes) ? mb_substr(trim((string) $data->notes), 0, 5000) : null,
                'status_changed_at' => now(),
            ]);

            $items = $data->items ?? ($lead !== null ? static::itemsFromLead($lead) : []);
            $this->saveItems->handle($quote, $items);

            $this->log->handle($quote, 'created', $actor, $logExtra);

            return $quote;
        }, 3);
    }

    public static function formatNumber(int $year, int $sequence): string
    {
        return sprintf('Q-%d-%04d', $year, $sequence);
    }

    /**
     * A valid Y-m-d date not in the past, else 5 working days from today (Tashkent).
     *
     * @throws ValidationException
     */
    public static function validUntil(?string $date): CarbonImmutable
    {
        $today = CarbonImmutable::now(Lead::TIMEZONE)->startOfDay();

        if (blank($date)) {
            return WorkingDays::add($today, Quote::VALID_WORKING_DAYS);
        }

        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $date, Lead::TIMEZONE) : false;

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date || $parsed->lt($today)) {
            throw ValidationException::withMessages(['valid_until' => __('crm.quotes.errors.valid_until')]);
        }

        return $parsed;
    }

    /**
     * The lead's products as quote lines (level prices); lines without a product as free text.
     *
     * @return list<QuoteItemData>
     */
    public static function itemsFromLead(Lead $lead): array
    {
        return $lead->items->map(fn (LeadItem $item): QuoteItemData => $item->product_id !== null
            ? new QuoteItemData(productId: $item->product_id, qty: $item->qty)
            : new QuoteItemData(name: $item->product_name, qty: $item->qty, price: 0))->values()->all();
    }
}
