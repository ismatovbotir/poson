<?php

namespace App\Actions\Analytics;

use App\Models\Lead;
use App\Models\Order;

/**
 * Plan 40 retention (owner: 12 months, YD-8): nulls yclid and ym_client_id
 * on leads and orders created before config('analytics.retention.ad_click_ids_months')
 * months ago. The rows themselves stay; updated_at is not touched.
 * Idempotent: only rows that still hold an id are updated.
 */
class PruneAdClickIds
{
    /**
     * @return array{leads: int, orders: int} rows cleared per table
     */
    public function handle(): array
    {
        $before = now()->subMonths(max(1, (int) config('analytics.retention.ad_click_ids_months', 12)));
        $cleared = [];

        foreach (['leads' => Lead::class, 'orders' => Order::class] as $table => $model) {
            $cleared[$table] = $model::query()
                ->toBase()
                ->where('created_at', '<', $before)
                ->where(fn ($query) => $query->whereNotNull('yclid')->orWhereNotNull('ym_client_id'))
                ->update(['yclid' => null, 'ym_client_id' => null]);
        }

        return $cleared;
    }
}
