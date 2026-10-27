<?php

namespace App\Data\Api;

use App\Enums\PriceSyncStatus;
use App\Models\PriceSyncLog;

/**
 * Outcome of App\Actions\Api\SyncPrices: the log row plus the per-item
 * statuses for the API response (request order).
 */
final readonly class PriceSyncResult
{
    /**
     * @param  list<array{code: string|null, status: string, errors?: list<string>}>  $items
     */
    public function __construct(
        public PriceSyncLog $log,
        public array $items = [],
    ) {}

    public function isRejected(): bool
    {
        return $this->log->status === PriceSyncStatus::Rejected;
    }

    /**
     * Body of the 200 response.
     *
     * @return array{sync_id: int, received: int, updated: int, unchanged: int, unknown: int, invalid: int, items: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'sync_id' => $this->log->id,
            'received' => $this->log->received,
            'updated' => $this->log->updated,
            'unchanged' => $this->log->unchanged,
            'unknown' => $this->log->unknown,
            'invalid' => $this->log->invalid,
            'items' => $this->items,
        ];
    }
}
