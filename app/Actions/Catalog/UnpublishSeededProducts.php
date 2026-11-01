<?php

namespace App\Actions\Catalog;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Category;
use App\Models\Product;
use App\Models\SettingsAudit;
use App\Models\User;
use Database\Seeders\ProductsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 16 P7: rollback of the direct publishing. Admin only, idempotent.
 *
 * Returns the still-untouched seeded products (recorded SKU, active, not
 * trashed, not price-synced, updated_at not later than created_at) to drafts,
 * then deactivates the import categories (PublishSeededProducts::CATEGORY_SLUGS)
 * that are untouched and no longer hold an active product. Anything an admin
 * edited is left alone. Writes no timestamps; the run-once publish marker stays,
 * so a later LaunchSeeder does not publish again. Audited (user, SKUs + counts
 * in the log, never texts or prices).
 */
class UnpublishSeededProducts
{
    /**
     * @return array{products: int, categories: list<string>}
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor, ?string $ip = null): array
    {
        if (! $actor->hasRole(Role::Admin)) {
            throw new AuthorizationException;
        }

        $skus = [];
        $slugs = [];

        DB::transaction(function () use (&$skus, &$slugs, $actor, $ip): void {
            Model::withoutEvents(function () use (&$skus, &$slugs): void {
                $recorded = ProductsSeeder::recordedSkus();

                $products = Product::query()->whereIn('sku', $recorded)->where('is_active', true)->whereNull('price_synced_at')->orderBy('id')->get();

                foreach ($products as $product) {
                    if (! PublishSeededProducts::isUntouched($product)) {
                        continue;
                    }

                    $product->timestamps = false;
                    $product->is_active = false;
                    $product->save();
                    $skus[] = $product->sku;
                }

                $categories = Category::query()->whereIn('slug', PublishSeededProducts::CATEGORY_SLUGS)->where('is_active', true)->get();

                foreach ($categories as $category) {
                    if (! PublishSeededProducts::isUntouched($category)) {
                        continue;
                    }

                    $stillActive = Product::query()->whereIn('category_id', PublishSeededProducts::subtreeIds($category))->where('is_active', true)->exists();

                    if (! $stillActive) {
                        $category->timestamps = false;
                        $category->is_active = false;
                        $category->save();
                        $slugs[] = $category->slug;
                    }
                }
            });

            SettingsAudit::record(SettingsArea::Catalog, 'products.unpublish_seeded', AuditAction::Unpublished, $actor, $ip);
        });

        PublishSeededProducts::flushCaches();

        Log::info('UnpublishSeededProducts: user_id='.$actor->id.' products='.count($skus).' categories='.implode(',', $slugs).' skus='.implode(',', $skus));

        return ['products' => count($skus), 'categories' => $slugs];
    }
}
