<?php

namespace App\Actions\Catalog;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Product;
use App\Models\SettingsAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Unlink from API" (plan 09, Admin only): price_synced_at and
 * price_sync_log_id back to null, so the admin form edits the prices and
 * the SKU again (until the next batch that sends
 * this SKU links it again). The prices themselves are kept. Audited in
 * settings_audits (area price_sync, key products.{id}).
 */
class UnlinkProductPriceSync
{
    public function handle(Product $product, ?User $user, ?string $ip): bool
    {
        return DB::transaction(function () use ($product, $user, $ip): bool {
            $unlinked = DB::table('products')->where('id', $product->id)->whereNotNull('price_synced_at')->update(['price_synced_at' => null, 'price_sync_log_id' => null]) > 0;

            if ($unlinked) {
                SettingsAudit::record(SettingsArea::PriceSync, 'products.'.$product->id, AuditAction::Unlinked, $user, $ip);
            }

            $product->refresh();

            return $unlinked;
        });
    }
}
