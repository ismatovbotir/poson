<?php

namespace App\Actions\Catalog;

use App\Enums\AuditAction;
use App\Enums\ProductPricingUnit;
use App\Enums\SettingsArea;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Carbon\CarbonInterface;
use Database\Seeders\ProductsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Plan 16 "publish directly" (security P1-P8): publishes the products
 * ProductsSeeder created as drafts, once (LaunchSeeder, after ProductsSeeder).
 *
 * - Targets only SKUs the seeder recorded that are still untouched: draft, not
 *   trashed, not price-synced, products.updated_at not later than created_at
 *   (+ TOLERANCE_SECONDS), and no media / document created or changed after it.
 *   An admin-edited, deactivated, trashed or synced product is never published.
 *   (Spec values and relations carry no timestamps; an edit of those through
 *   SaveProduct bumps products.updated_at.)
 * - A still-untouched draft that lacks specs the current data file now has
 *   (e.g. ribbon ink side / core) first gets those specs through SaveProduct
 *   (specs only; the rest of its data is passed back unchanged), then is
 *   activated through SetProductActive, which refuses incomplete products.
 *   Null price stays null: such a product is published as "Request a price".
 * - Drafts left over (still_draft, listed by SKU in the marker value) are never retried by
 *   later runs: an admin publishes them after filling the specs.
 * - Run once: the setting MARKER_KEY is written by the first run, so a later
 *   LaunchSeeder never re-publishes a product an admin deactivated.
 * - The 5 import categories (CATEGORY_SLUGS) are activated only while inactive,
 *   untouched and holding at least one active seeded product (so on a fresh
 *   install, right after ProductsSeeder, too). Never an empty public category.
 * - Timestamps are never written (updated_at stays as it was), model events are
 *   off, and the sitemap, llms.txt and the category tree are flushed once after
 *   commit. One audit row (actor null = system / launch-seeder); logs hold SKUs
 *   and counts only.
 */
class PublishSeededProducts
{
    public const MARKER_KEY = 'launch_products_published';

    /** Seconds between created_at and updated_at that still count as untouched. */
    public const TOLERANCE_SECONDS = 60;

    /**
     * @var list<string>
     */
    public const CATEGORY_SLUGS = ['textile-tapes', 'accessories-parts', 'accessories', 'spare-parts', 'price-checkers'];

    /**
     * Data rows to re-apply specs from instead of the data files (tests).
     *
     * @var list<array<string, mixed>>|null
     *
     * @internal Test hook.
     */
    public ?array $rows = null;

    public function __construct(private SaveProduct $saveProduct, private SetProductActive $setActive) {}

    /**
     * @return array{ran: bool, published: int, respecced: int, still_draft: int, skipped_edited: int, categories: list<string>}
     */
    public function handle(?User $actor = null, ?string $ip = null): array
    {
        $stats = ['ran' => false, 'published' => 0, 'respecced' => 0, 'still_draft' => 0, 'skipped_edited' => 0, 'categories' => []];

        if (Setting::query()->where('key', self::MARKER_KEY)->exists()) {
            return $stats;
        }

        $skus = [];
        $draftSkus = [];

        // Concurrent runs queue up on the lock; the marker is re-checked inside it and the transaction.
        $ran = Cache::lock('launch-publish', 600)->block(30, function () use (&$stats, &$skus, &$draftSkus, $actor, $ip): bool {
            return DB::transaction(function () use (&$stats, &$skus, &$draftSkus, $actor, $ip): bool {
                if (Setting::query()->where('key', self::MARKER_KEY)->lockForUpdate()->exists()) {
                    return false;
                }

                Model::withoutEvents(function () use (&$stats, &$skus, &$draftSkus): void {
                    $recorded = ProductsSeeder::recordedSkus();
                    $rows = collect($this->rows ?? ProductsSeeder::loadRows())->keyBy(fn (array $row): string => mb_strtoupper(trim((string) $row['sku'])));
                    $attributes = null;

                    $drafts = Product::query()
                        ->whereIn('sku', $recorded)
                        ->where('is_active', false)
                        ->whereNull('price_synced_at')
                        ->orderBy('id')
                        ->get();

                    foreach ($drafts as $product) {
                        if (! self::isUntouched($product)) {
                            $stats['skipped_edited']++;

                            continue;
                        }

                        $product->timestamps = false;
                        $row = $rows->get($product->sku);

                        if ($product->has_missing_specs && $row !== null && ! ($row['has_missing_specs'] ?? false) && $product->pricing_unit !== ProductPricingUnit::Label) {
                            $attributes ??= Attribute::query()->with('options')->get()->keyBy('code');

                            if ($this->respec($product, $row, $attributes)) {
                                $stats['respecced']++;
                            }

                            $product = Product::query()->findOrFail($product->id);
                            $product->timestamps = false;
                        }

                        if ($this->setActive->handle($product, true)) {
                            $stats['published']++;
                            $skus[] = $product->sku;
                        } else {
                            $stats['still_draft']++;
                            $draftSkus[] = $product->sku;
                        }
                    }

                    $stats['categories'] = $this->activateCategories($recorded);
                });

                Setting::query()->create([
                    'key' => self::MARKER_KEY,
                    'group' => 'system',
                    'value' => [
                        'at' => now()->toIso8601String(),
                        'published' => $stats['published'],
                        'respecced' => $stats['respecced'],
                        'skipped_edited' => $stats['skipped_edited'],
                        'categories' => $stats['categories'],
                        'still_draft' => $stats['still_draft'],
                        'still_draft_skus' => $draftSkus,
                    ],
                ]);

                SettingsAudit::record(SettingsArea::Catalog, ($actor ? 'products' : 'launch-seeder').'.publish_seeded', AuditAction::Published, $actor, $ip);

                return true;
            });
        });

        if (! $ran) {
            return ['ran' => false, ...array_slice($stats, 1)];
        }

        $stats['ran'] = true;

        self::flushCaches();

        Log::info('PublishSeededProducts: published='.$stats['published'].' respecced='.$stats['respecced'].' still_draft='.$stats['still_draft'].' skipped_edited='.$stats['skipped_edited'].' categories='.implode(',', $stats['categories']).' skus='.implode(',', $skus));

        return $stats;
    }

    /**
     * Untouched since it was seeded: updated_at not later than created_at (+ tolerance),
     * and no media or document created or changed after that. Relation changes touch the
     * product (AddProductRelation / RemoveProductRelation), so they count as edits too.
     */
    public static function isUntouched(Model $model): bool
    {
        $cutoff = $model->created_at?->copy()->addSeconds(self::TOLERANCE_SECONDS);

        if (! $cutoff instanceof CarbonInterface || $model->updated_at === null || $model->updated_at->greaterThan($cutoff)) {
            return false;
        }

        if ($model instanceof Product) {
            foreach ([$model->media(), $model->documents()] as $relation) {
                if ($relation->where(fn ($query) => $query->where('created_at', '>', $cutoff)->orWhere('updated_at', '>', $cutoff))->exists()) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function flushCaches(): void
    {
        CategoryTree::flush();
        Sitemap::flush();
        LlmsTxt::flush();
    }

    /**
     * Ids of the category and all its descendants.
     *
     * @return list<int>
     */
    public static function subtreeIds(Category $category): array
    {
        $parents = Category::query()->pluck('parent_id', 'id');
        $ids = [$category->id];

        for ($i = 0; $i < count($ids); $i++) {
            foreach ($parents as $id => $parentId) {
                if ($parentId === $ids[$i] && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Re-apply the current data-file specs to an untouched draft. Everything
     * else is passed back as it is stored.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, Attribute>  $attributes
     */
    private function respec(Product $product, array $row, $attributes): bool
    {
        $specs = app(ProductsSeeder::class)->specRows($product->sku, $row['specs'] ?? [], $attributes);

        $data = [
            'type' => $product->type,
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'sku' => $product->sku,
            'model' => $product->model,
            'name' => $product->getTranslations('name'),
            'short_description' => $product->getTranslations('short_description'),
            'description' => $product->getTranslations('description'),
            'meta_title' => $product->getTranslations('meta_title'),
            'meta_description' => $product->getTranslations('meta_description'),
            'price' => $product->price,
            'price_dealer' => $product->price_dealer,
            'price_wholesale' => $product->price_wholesale,
            'pricing_unit' => $product->pricing_unit,
            'unit' => $product->unit,
            'min_qty' => $product->min_qty,
            'sale_mode' => $product->sale_mode,
            'stock_status' => $product->stock_status,
            'warranty_months' => $product->warranty_months,
            'badge' => $product->badge,
            'is_active' => false,
            'is_featured' => $product->is_featured,
            'ikpu_code' => $product->ikpu_code,
            'package_code' => $product->package_code,
        ];

        try {
            $this->saveProduct->handle($product, $data, $specs);
        } catch (ValidationException) {
            Log::warning("PublishSeededProducts: {$product->sku} specs not re-applied");

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $recorded
     * @return list<string> slugs activated
     */
    private function activateCategories(array $recorded): array
    {
        $activated = [];
        $categories = Category::query()->whereIn('slug', self::CATEGORY_SLUGS)->where('is_active', false)->get()
            ->sortBy(fn (Category $category): int => count(self::subtreeIds($category)));
        $parents = Category::query()->whereIn('id', $categories->pluck('parent_id')->filter())->get()->keyBy('id');

        // Smallest subtree first, so a parent sees its children's products (and their activation).
        foreach ($categories as $category) {
            if (! self::isUntouched($category)) {
                continue;
            }

            // Never a public category under an inactive parent, unless that parent is an import category activated in this run.
            $parent = $parents->get($category->parent_id);

            if ($parent !== null && ! $parent->is_active && ! (in_array($parent->slug, self::CATEGORY_SLUGS, true) && self::isUntouched($parent))) {
                continue;
            }

            $hasActiveSeeded = Product::query()
                ->whereIn('category_id', self::subtreeIds($category))
                ->whereIn('sku', $recorded)
                ->where('is_active', true)
                ->exists();

            if ($hasActiveSeeded) {
                $category->timestamps = false;
                $category->is_active = true;
                $category->save();
                $activated[] = $category->slug;
            }
        }

        return $activated;
    }
}
