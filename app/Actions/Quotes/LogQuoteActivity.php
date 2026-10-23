<?php

namespace App\Actions\Quotes;

use App\Enums\ActivityType;
use App\Models\Quote;
use App\Models\User;

/**
 * One "quote" history entry, written on the quote, its customer and its
 * lead (when linked), so each timeline tells the quote's story.
 * meta: {quote_id, number, action (created / sent / accepted / rejected /
 * expired / duplicated / deleted), via?, from_number?}.
 */
class LogQuoteActivity
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function handle(Quote $quote, string $action, ?User $actor, array $extra = []): void
    {
        $meta = array_merge(['quote_id' => $quote->id, 'number' => $quote->number, 'action' => $action], array_filter($extra, fn (mixed $value): bool => $value !== null));

        $attributes = [
            'user_id' => $actor?->id,
            'type' => ActivityType::Quote,
            'meta' => $meta,
            'created_at' => now(),
        ];

        if ($quote->exists) {
            $quote->activities()->create($attributes);
        }

        $quote->customer()->first()?->activities()->create($attributes);

        if ($quote->lead_id !== null) {
            $quote->lead()->first()?->activities()->create($attributes);
        }
    }
}
