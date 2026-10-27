<?php

namespace App\Policies;

use App\Models\PriceSyncLog;
use App\Models\User;

/**
 * Catalog → Price sync log (plan 09). price-sync.view is seeded but granted
 * to no role besides Admin (who also passes through Gate::before). Rows are
 * written only by App\Actions\Api\SyncPrices.
 */
class PriceSyncLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('price-sync.view');
    }

    public function view(User $user, PriceSyncLog $priceSyncLog): bool
    {
        return $user->can('price-sync.view');
    }
}
