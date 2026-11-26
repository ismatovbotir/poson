<?php

namespace App\Support\Office;

use App\Models\Customer;
use App\Support\Telegram\Bot\BotText;

/**
 * Customers an Admin may link to a group or a client member (plan 44 §2,
 * OG-3 search rules): a term needs at least 2 characters, LIKE wildcards
 * are escaped (Customer::search), at most 20 results, only id and name are
 * read (never the phone, e-mail or INN).
 */
final class CustomerCandidates
{
    public const MIN_TERM = 2;

    public const LIMIT = 20;

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function search(string $term): array
    {
        $term = BotText::clean($term);

        if (mb_strlen($term) < self::MIN_TERM || mb_strlen($term) > 64) {
            return [];
        }

        return Customer::query()
            ->search($term)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['customers.id', 'customers.name'])
            ->map(fn (Customer $customer): array => ['id' => $customer->id, 'name' => $customer->name])
            ->all();
    }

    public static function name(?int $id): ?string
    {
        return $id === null ? null : Customer::query()->whereKey($id)->value('name');
    }
}
