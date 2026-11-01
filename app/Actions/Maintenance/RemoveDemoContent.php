<?php

namespace App\Actions\Maintenance;

use App\Actions\Banners\DeleteBanner;
use App\Actions\Catalog\ForceDeleteProduct;
use App\Actions\News\DeleteNewsPost;
use App\Enums\NewsKind;
use App\Models\Banner;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\CompanySettings;
use App\Support\DemoContent;
use App\Support\Site\BannerDirectory;
use App\Support\Site\LlmsTxt;
use App\Support\Site\NewsDirectory;
use App\Support\Site\Sitemap;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Remove the launch demo content (plan 15 A6): only the products, news and
 * banners App\Support\DemoContent identifies, with their media and files,
 * through the existing delete actions. Products are deleted permanently
 * (trashed ones too), so their SKUs are free for the real products; order,
 * quote and lead lines keep their snapshots (product_id is nulled).
 * Anything else is never touched. Run it before real products go in
 * (`php artisan demo:remove`).
 */
class RemoveDemoContent
{
    public function __construct(
        protected ForceDeleteProduct $forceDeleteProduct,
        protected DeleteNewsPost $deleteNewsPost,
        protected DeleteBanner $deleteBanner,
    ) {}

    /**
     * What would be removed: rows whose ID the seeders recorded and that still
     * match (SKU / slug / title). Rows edited after the seeding are listed in
     * "skipped" and left alone unless $includeEdited.
     *
     * @return array{products: Collection<int, Product>, news: Collection<int, NewsPost>, banners: Collection<int, Banner>, skipped: list<string>}
     */
    public function find(bool $includeEdited = false): array
    {
        $record = DemoContent::record();

        if ($record === null) {
            return ['products' => collect(), 'news' => collect(), 'banners' => collect(), 'skipped' => []];
        }

        $cutoff = DemoContent::editedAfter();
        $skipped = [];

        $keep = function (Collection $rows, string $label) use ($includeEdited, $cutoff, &$skipped): Collection {
            return $rows->filter(function ($row) use ($includeEdited, $cutoff, $label, &$skipped): bool {
                if (! $includeEdited && $cutoff !== null && $row->updated_at?->greaterThan($cutoff)) {
                    $skipped[] = $label.' #'.$row->id;

                    return false;
                }

                return true;
            })->values();
        };

        return [
            'products' => $keep(Product::withTrashed()->whereIn('id', $record['product_ids'])->whereIn('sku', DemoContent::productSkus())->orderBy('id')->get(), 'product'),
            'news' => $keep(NewsPost::query()->ofKind(NewsKind::News)->whereIn('id', $record['news_ids'])->whereIn('slug', DemoContent::NEWS_SLUGS)->orderBy('id')->get(), 'news'),
            'banners' => $keep($this->banners($record['banner_ids']), 'banner'),
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{products: int, news: int, banners: int, skipped: int} the number removed (and skipped as edited)
     */
    public function handle(bool $includeEdited = false, ?int $userId = null): array
    {
        $found = $this->find($includeEdited);

        foreach ($found['news'] as $post) {
            $this->deleteNewsPost->handle($post);
        }

        foreach ($found['banners'] as $banner) {
            $this->deleteBanner->handle($banner);
        }

        foreach ($found['products'] as $product) {
            $this->forceDeleteProduct->handle($product);
        }

        DemoContent::markRemoved($found['skipped'] === []);

        CategoryTree::flush();
        NewsDirectory::flushAll();
        BannerDirectory::flush();
        Sitemap::flush();
        LlmsTxt::flush();
        CompanySettings::flush();

        Log::info('demo:remove', [
            'user_id' => $userId,
            'include_edited' => $includeEdited,
            'product_ids' => $found['products']->pluck('id')->all(),
            'skus' => $found['products']->pluck('sku')->all(),
            'news_ids' => $found['news']->pluck('id')->all(),
            'banner_ids' => $found['banners']->pluck('id')->all(),
            'skipped' => $found['skipped'],
        ]);

        return [
            'products' => $found['products']->count(),
            'news' => $found['news']->count(),
            'banners' => $found['banners']->count(),
            'skipped' => count($found['skipped']),
        ];
    }

    /**
     * Banners matching a demo uz title + link pair (compared in PHP: titles are JSON).
     *
     * @param  list<int>  $ids
     * @return Collection<int, Banner>
     */
    protected function banners(array $ids): Collection
    {
        $pairs = array_map(fn (array $banner): string => $banner['link']."\n".$banner['title_uz'], DemoContent::BANNERS);

        return Banner::query()
            ->whereIn('id', $ids)
            ->whereIn('link', array_column(DemoContent::BANNERS, 'link'))
            ->orderBy('id')
            ->get()
            ->filter(fn (Banner $banner): bool => in_array($banner->link."\n".$banner->getTranslation('title', 'uz', false), $pairs, true))
            ->values();
    }
}
