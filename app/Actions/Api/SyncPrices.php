<?php

namespace App\Actions\Api;

use App\Data\Api\PriceSyncResult;
use App\Enums\PriceSyncStatus;
use App\Events\PriceSyncCompleted;
use App\Models\ApiToken;
use App\Models\PriceSyncLog;
use App\Support\Site\Sitemap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Apply one price batch from the price API (POST /api/v1/prices, plan 09).
 *
 * Input: the JSON body decoded with objects (json_decode without assoc), so
 * a JSON object and an array stay distinguishable: {"items": [{…}, …]},
 * 1 to MAX_ITEMS items, otherwise the whole batch is rejected (logged, 422).
 *
 * Every item is checked on its own (errors are machine codes):
 * - code: string, trimmed + upper-cased, 1–CODE_MAX characters; matched to
 *   products.sku, soft-deleted products included;
 * - retail: JSON integer, 1..MAX_SOM soʻm (no floats, strings or booleans);
 * - dealer / wholesale: the key must be present; an integer 1..MAX_SOM, or
 *   null / 0 = no price at that level (stored null, falls back to Retail;
 *   decision 2026-10-27); negative is invalid;
 * - a code sent twice makes every occurrence invalid (duplicate_code).
 * Prices are stored × 100 (tiyin). An item replaces all three levels and
 * nothing else (the row's updated_at is touched); equal values = unchanged.
 *
 * One transaction (retried up to TRANSACTION_ATTEMPTS times on deadlocks):
 * the matched rows are locked in SKU order, each changed row gets one
 * query-builder update (no model events), the log row is written, then
 * every matched valid row gets price_synced_at = now and price_sync_log_id
 * in one update. After the commit (failures there are only reported): the
 * sitemap cache is flushed once when something changed, and
 * PriceSyncCompleted is dispatched.
 *
 * An unexpected failure rolls everything back, is reported, and is logged
 * as a rejected batch with error "server_error" (which also alerts the
 * finance chat); the controller answers 500. Retrying is safe: batches are
 * idempotent.
 */
class SyncPrices
{
    public const MAX_ITEMS = 100;

    /**
     * Largest price in whole soʻm (just under 10^15; × 100 stays within SaveProduct::MAX_PRICE).
     */
    public const MAX_SOM = 999_999_999_999_999;

    public const TIYIN_PER_SOM = 100;

    public const CODE_MAX = 64;

    public const TRANSACTION_ATTEMPTS = 3;

    public const SERVER_ERROR = 'server_error';

    /**
     * Item status codes.
     */
    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const UNKNOWN = 'unknown';

    public const INVALID = 'invalid';

    /**
     * Price levels of an item: request key => products column.
     */
    public const LEVELS = ['retail' => 'price', 'dealer' => 'price_dealer', 'wholesale' => 'price_wholesale'];

    /**
     * @param  mixed  $payload  The JSON body decoded with objects (stdClass)
     */
    public function handle(mixed $payload, ?ApiToken $token, ?string $ip): PriceSyncResult
    {
        $startedAt = hrtime(true);

        $error = $this->batchError($payload);

        if ($error !== null) {
            $received = $payload instanceof stdClass && is_array($payload->items ?? null) ? count($payload->items) : 0;

            return $this->reject($error, $token, $ip, $startedAt, $received);
        }

        $items = $this->parseItems($payload->items);

        try {
            $result = $this->apply($items, $token, $ip, $startedAt);
        } catch (Throwable $exception) {
            report($exception);

            return $this->reject(self::SERVER_ERROR, $token, $ip, $startedAt, count($items));
        }

        // Committed: a failure from here on must not turn the batch into a server error.
        try {
            if ($result->log->updated > 0) {
                DB::afterCommit(fn () => Sitemap::flush());
            }

            PriceSyncCompleted::dispatch($result->log);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $result;
    }

    /**
     * Log a batch refused as a whole (wrong content type, bad JSON, bad
     * items list, server error) for an authenticated token.
     */
    public function reject(string $error, ?ApiToken $token, ?string $ip, ?int $startedAt = null, int $received = 0): PriceSyncResult
    {
        $log = PriceSyncLog::query()->create([
            ...$this->tokenColumns($token, $ip),
            'status' => PriceSyncStatus::Rejected,
            'received' => min($received, 1_000_000),
            'error' => mb_substr($error, 0, 50),
            'duration_ms' => $this->elapsedMs($startedAt ?? hrtime(true)),
            'created_at' => now(),
        ]);

        PriceSyncCompleted::dispatch($log);

        return new PriceSyncResult($log);
    }

    /**
     * Machine code of a whole-batch problem, or null.
     */
    public function batchError(mixed $payload): ?string
    {
        if (! $payload instanceof stdClass) {
            return 'body_not_object';
        }

        if (! property_exists($payload, 'items')) {
            return 'items_missing';
        }

        $items = $payload->items;

        if (! is_array($items)) {
            return 'items_not_list';
        }

        if ($items === []) {
            return 'items_empty';
        }

        if (count($items) > self::MAX_ITEMS) {
            return 'too_many_items';
        }

        return null;
    }

    /**
     * @param  list<mixed>  $rawItems
     * @return list<array{code: string|null, errors: list<string>, prices: array<string, int|null>}>
     */
    private function parseItems(array $rawItems): array
    {
        $items = array_map($this->parseItem(...), array_values($rawItems));

        // A code sent more than once: every occurrence is invalid.
        $counts = [];

        foreach ($items as $item) {
            if ($item['code'] !== null) {
                $counts[$item['code']] = ($counts[$item['code']] ?? 0) + 1;
            }
        }

        foreach ($items as $index => $item) {
            if ($item['code'] !== null && $counts[$item['code']] > 1) {
                $items[$index]['errors'][] = 'duplicate_code';
            }
        }

        return $items;
    }

    /**
     * @return array{code: string|null, errors: list<string>, prices: array<string, int|null>}
     */
    private function parseItem(mixed $raw): array
    {
        if (! $raw instanceof stdClass) {
            return ['code' => null, 'errors' => ['item_not_object'], 'prices' => []];
        }

        $raw = get_object_vars($raw);
        $errors = [];
        $code = null;
        $rawCode = $raw['code'] ?? null;

        if (! is_string($rawCode) && $rawCode !== null) {
            $errors[] = 'code_not_string';
        } else {
            $normalized = mb_strtoupper(trim((string) $rawCode));

            if ($normalized === '') {
                $errors[] = 'code_required';
            } elseif (mb_strlen($normalized) > self::CODE_MAX) {
                $errors[] = 'code_too_long';
            } else {
                $code = $normalized;
            }
        }

        $prices = [];

        foreach (array_keys(self::LEVELS) as $level) {
            $prices[$level] = $this->price($raw, $level, $errors);
        }

        return ['code' => $code, 'errors' => $errors, 'prices' => $prices];
    }

    /**
     * Tiyin of one level; null = no price at that level (dealer / wholesale only).
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $errors
     */
    private function price(array $raw, string $level, array &$errors): ?int
    {
        $retail = $level === 'retail';

        if (! array_key_exists($level, $raw) || ($retail && $raw[$level] === null)) {
            $errors[] = "{$level}_required";

            return null;
        }

        $value = $raw[$level];

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            $errors[] = "{$level}_not_integer";

            return null;
        }

        if (! $retail && $value === 0) {
            return null;
        }

        if ($value < 1 || $value > self::MAX_SOM) {
            $errors[] = "{$level}_out_of_range";

            return null;
        }

        return $value * self::TIYIN_PER_SOM;
    }

    /**
     * @param  list<array{code: string|null, errors: list<string>, prices: array<string, int|null>}>  $items
     */
    private function apply(array $items, ?ApiToken $token, ?string $ip, int $startedAt): PriceSyncResult
    {
        $codes = [];

        foreach ($items as $item) {
            if ($item['errors'] === []) {
                $codes[] = $item['code'];
            }
        }

        // A fixed lock order keeps two concurrent batches from deadlocking each other.
        sort($codes, SORT_STRING);

        return DB::transaction(function () use ($items, $codes, $token, $ip, $startedAt): PriceSyncResult {
            $now = Carbon::now();

            $products = $codes === [] ? collect() : DB::table('products')
                ->whereIn('sku', $codes)
                ->orderBy('sku')
                ->lockForUpdate()
                ->get(['id', 'sku', ...array_values(self::LEVELS)])
                ->keyBy(fn (object $row): string => mb_strtoupper((string) $row->sku));

            $response = [];
            $results = [];
            $matchedIds = [];
            $unknownCodes = [];
            $counts = [self::UPDATED => 0, self::UNCHANGED => 0, self::UNKNOWN => 0, self::INVALID => 0];

            foreach ($items as $item) {
                if ($item['errors'] !== []) {
                    $counts[self::INVALID]++;
                    $response[] = ['code' => $item['code'], 'status' => self::INVALID, 'errors' => $item['errors']];
                    $results[] = ['code' => $item['code'], 'status' => self::INVALID, 'errors' => $item['errors']];

                    continue;
                }

                $product = $products->get($item['code']);

                if ($product === null) {
                    $counts[self::UNKNOWN]++;
                    $unknownCodes[] = $item['code'];
                    $response[] = ['code' => $item['code'], 'status' => self::UNKNOWN];
                    $results[] = ['code' => $item['code'], 'status' => self::UNKNOWN];

                    continue;
                }

                $old = [];

                foreach (self::LEVELS as $level => $column) {
                    $old[$level] = $product->{$column} !== null ? (int) $product->{$column} : null;
                }

                $new = $item['prices'];
                $matchedIds[] = (int) $product->id;

                if ($old === $new) {
                    $counts[self::UNCHANGED]++;
                    $response[] = ['code' => $item['code'], 'status' => self::UNCHANGED];
                    $results[] = ['product_id' => (int) $product->id, 'code' => $item['code'], 'status' => self::UNCHANGED];

                    continue;
                }

                DB::table('products')->where('id', $product->id)->update([
                    'price' => $new['retail'],
                    'price_dealer' => $new['dealer'],
                    'price_wholesale' => $new['wholesale'],
                    'updated_at' => $now,
                ]);

                $counts[self::UPDATED]++;
                $response[] = ['code' => $item['code'], 'status' => self::UPDATED];
                $results[] = ['product_id' => (int) $product->id, 'code' => $item['code'], 'status' => self::UPDATED, 'old' => $old, 'new' => $new];
            }

            $log = PriceSyncLog::query()->create([
                ...$this->tokenColumns($token, $ip),
                'status' => $counts[self::UNKNOWN] + $counts[self::INVALID] > 0 ? PriceSyncStatus::Partial : PriceSyncStatus::Success,
                'received' => count($items),
                'updated' => $counts[self::UPDATED],
                'unchanged' => $counts[self::UNCHANGED],
                'unknown' => $counts[self::UNKNOWN],
                'invalid' => $counts[self::INVALID],
                'unknown_codes' => array_values(array_unique($unknownCodes)),
                'results' => $results,
                'duration_ms' => $this->elapsedMs($startedAt),
                'created_at' => $now,
            ]);

            if ($matchedIds !== []) {
                DB::table('products')->whereIn('id', $matchedIds)->update(['price_synced_at' => $now, 'price_sync_log_id' => $log->id]);
            }

            return new PriceSyncResult($log, $response);
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @return array{api_token_id: int|null, token_name: string|null, ip: string|null}
     */
    private function tokenColumns(?ApiToken $token, ?string $ip): array
    {
        return [
            'api_token_id' => $token?->id,
            'token_name' => $token !== null ? mb_substr($token->name, 0, 100) : null,
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
        ];
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) max(0, intdiv(hrtime(true) - $startedAt, 1_000_000));
    }
}
