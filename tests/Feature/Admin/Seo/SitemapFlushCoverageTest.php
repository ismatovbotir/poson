<?php

use App\Actions\Catalog\DeleteBrand;
use App\Actions\Catalog\DeleteCategory;
use App\Actions\Catalog\ForceDeleteProduct;
use App\Actions\Catalog\ReorderCategory;
use App\Actions\Catalog\RestoreProduct;
use App\Actions\News\DeleteNewsPost;
use App\Actions\Pages\DeletePage;
use App\Enums\Role;
use App\Livewire\Admin\Products\Index;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Support\Site\Sitemap;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * The sitemap is cached for a week, so every path that changes what it lists
 * must flush it. Each test warms the cache, runs one mutation path and expects
 * the cache to be gone.
 */
function warmSitemap(): void
{
    Cache::flush();
    app(Sitemap::class)->xml();
    expect(Cache::has(Sitemap::CACHE_KEY))->toBeTrue()->and(Cache::has(Sitemap::URLS_KEY))->toBeTrue();
}

function expectSitemapFlushed(): void
{
    expect(Cache::has(Sitemap::CACHE_KEY))->toBeFalse()->and(Cache::has(Sitemap::URLS_KEY))->toBeFalse();
}

it('flushes on the bulk publish, unpublish and delete of products', function (string $action) {
    $products = $action === 'bulkActivate' ? Product::factory()->count(2)->inactive()->create() : Product::factory()->count(2)->create();
    warmSitemap();

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Index::class)
        ->set('selected', $products->modelKeys())
        ->call($action);

    expectSitemapFlushed();
})->with(['bulkActivate', 'bulkDeactivate', 'bulkDelete']);

it('flushes on reordering categories (the sitemap lists them by sort)', function () {
    [$first, $second] = Category::factory()->count(2)->sequence(['sort' => 1], ['sort' => 2])->create();
    warmSitemap();

    app(ReorderCategory::class)->handle($second, 0);

    expectSitemapFlushed();
});

it('flushes when a product is restored or force-deleted', function () {
    $product = Product::factory()->create();
    $product->delete();
    warmSitemap();
    app(RestoreProduct::class)->handle($product->fresh());
    expectSitemapFlushed();

    warmSitemap();
    $product->fresh()->delete();
    warmSitemap();
    app(ForceDeleteProduct::class)->handle(Product::withTrashed()->find($product->id));
    expectSitemapFlushed();
});

it('flushes when a category, a brand, a page or a news post is deleted', function () {
    $category = Category::factory()->create();
    warmSitemap();
    app(DeleteCategory::class)->handle($category);
    expectSitemapFlushed();

    $brand = Brand::factory()->create();
    warmSitemap();
    app(DeleteBrand::class)->handle($brand);
    expectSitemapFlushed();

    $page = Page::factory()->create();
    warmSitemap();
    app(DeletePage::class)->handle($page);
    expectSitemapFlushed();

    $post = NewsPost::factory()->create();
    warmSitemap();
    app(DeleteNewsPost::class)->handle($post);
    expectSitemapFlushed();
});
