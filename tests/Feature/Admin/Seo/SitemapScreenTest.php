<?php

use App\Actions\Seo\RefreshSitemap;
use App\Enums\Role;
use App\Livewire\Admin\Seo\Sitemap as SitemapScreen;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Support\Admin\Menu;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\Sitemap;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

beforeEach(function () {
    openSearchIndexing();
    Cache::flush();
});

it('lets Admin and Content manager open the screen and the menu item shows for them only', function (Role $role) {
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.sitemap'))
        ->assertOk()
        ->assertSee(__('sitemap.refresh'))
        ->assertSee(route('sitemap'), false);

    expect(Menu::find(Menu::for($user), 'sitemap'))->not->toBeNull();
})->with([
    'admin' => [Role::Admin],
    'content manager' => [Role::ContentManager],
]);

it('forbids the screen and a direct refresh to the sales manager and service engineer', function (Role $role) {
    $user = staffUser($role);
    $this->mock(RefreshSitemap::class)->shouldNotReceive('handle');

    $this->actingAs($user)->get(route('admin.sitemap'))->assertForbidden();
    Livewire::actingAs($user)->test(SitemapScreen::class)->assertForbidden();

    expect(Menu::find(Menu::for($user), 'sitemap'))->toBeNull();
})->with([
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
]);

it('rejects a direct refresh call from a user who may view but not refresh', function () {
    $user = staffUser(Role::ContentManager);
    $user->roles()->detach();
    $user->givePermissionTo('sitemap.view');
    $this->mock(RefreshSitemap::class)->shouldNotReceive('handle');

    Livewire::actingAs($user)->test(SitemapScreen::class)->call('refresh')->assertForbidden();
});

it('grants the permissions to Admin and Content manager only, idempotently', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    foreach (['sitemap.view', 'sitemap.refresh'] as $permission) {
        expect(Spatie\Permission\Models\Role::findByName('admin')->hasPermissionTo($permission))->toBeTrue()
            ->and(Spatie\Permission\Models\Role::findByName('content_manager')->hasPermissionTo($permission))->toBeTrue()
            ->and(Spatie\Permission\Models\Role::findByName('sales_manager')->hasPermissionTo($permission))->toBeFalse()
            ->and(Spatie\Permission\Models\Role::findByName('service_engineer')->hasPermissionTo($permission))->toBeFalse();
    }
});

it('says the sitemap is not generated yet when the cache is empty and after a flush', function () {
    $admin = staffUser();

    Livewire::actingAs($admin)->test(SitemapScreen::class)
        ->assertSee(__('sitemap.not_generated'))
        ->assertSeeHtml('data-sitemap-state="empty"');

    app(Sitemap::class)->xml();
    Livewire::actingAs($admin)->test(SitemapScreen::class)->assertSeeHtml('data-sitemap-state="ready"');

    Sitemap::flush();
    Livewire::actingAs($admin)->test(SitemapScreen::class)->assertSeeHtml('data-sitemap-state="empty"');
    expect(Cache::has(Sitemap::URLS_KEY))->toBeFalse();
});

it('keeps the public XML byte-identical and caches it for a week with its meta', function () {
    Product::factory()->create(['slug' => 'zebra-ds2208']);

    $sitemap = app(Sitemap::class);
    $built = $sitemap->build();
    $first = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($first)->toBe($built)
        ->and(config('site.sitemap_cache_minutes'))->toBe(10080)
        ->and(Cache::get(Sitemap::CACHE_KEY))->toHaveKeys(['xml', 'generated_at', 'expires_at', 'counts'])
        ->and(Cache::get(Sitemap::CACHE_KEY)['xml'])->toBe($first);

    $payload = $sitemap->payload();
    expect(now()->parse($payload['expires_at'])->diffInDays(now()->parse($payload['generated_at']), true))->toBeGreaterThan(6.9);
});

it('treats an old string value under the cache key as a miss and rebuilds', function () {
    Cache::forever(Sitemap::CACHE_KEY, 'stale');

    expect($this->get('/sitemap.xml')->assertOk()->getContent())->toContain('<urlset')
        ->and(Cache::get(Sitemap::CACHE_KEY))->toBeArray();
});

it('refresh rebuilds the sitemap and moves generated_at', function () {
    $this->travelTo(now()->setTime(10, 0));
    $admin = staffUser();
    Product::factory()->create(['slug' => 'first-product']);
    app(Sitemap::class)->xml();
    $before = app(Sitemap::class)->payload()['generated_at'];

    // A change that bypasses the model events, so only the refresh can pick it up.
    Product::query()->toBase()->update(['slug' => 'renamed-product']);
    $this->travel(5)->minutes();

    Livewire::actingAs($admin)->test(SitemapScreen::class)
        ->call('refresh')
        ->assertDispatched('toast');

    $payload = app(Sitemap::class)->payload();
    expect($payload['generated_at'])->not->toBe($before)
        ->and($payload['xml'])->toContain('renamed-product')->not->toContain('first-product');
});

it('refreshes on APP_URL even when the request carries a foreign Host', function () {
    config(['app.url' => 'https://poson.test']);
    Product::factory()->create();
    URL::forceRootUrl('http://evil.test');
    app(RefreshSitemap::class)->handle();
    URL::forceRootUrl(null);

    $xml = app(Sitemap::class)->payload()['xml'];
    expect($xml)->toContain('https://poson.test/uz')->not->toContain('evil.test')
        ->and(json_encode(app(Sitemap::class)->urls()))->not->toContain('evil.test');
});

it('refuses the 7th refresh within a minute without rebuilding', function () {
    $admin = staffUser();
    $this->mock(RefreshSitemap::class)->shouldReceive('handle')->times(6)->andReturn(['xml' => '', 'generated_at' => now()->toIso8601String(), 'expires_at' => now()->toIso8601String(), 'counts' => []]);

    $component = Livewire::actingAs($admin)->test(SitemapScreen::class);

    foreach (range(1, 6) as $ignored) {
        $component->call('refresh')->assertDispatched('toast', title: __('sitemap.refreshed'), body: __('sitemap.refreshed_body', ['count' => 0]));
    }

    $component->call('refresh')->assertDispatched('toast', title: __('admin.toast.error'), body: __('sitemap.rate_limited'), type: 'error');
    RateLimiter::clear('sitemap-refresh:'.$admin->id);
});

it('does not start a second build while the build lock is held', function () {
    $lock = Cache::lock(Sitemap::BUILD_LOCK, 120);
    expect($lock->get())->toBeTrue();

    Livewire::actingAs(staffUser())->test(SitemapScreen::class)
        ->call('refresh')
        ->assertDispatched('toast', title: __('admin.toast.error'), body: __('sitemap.busy'), type: 'error');

    expect(Cache::has(Sitemap::CACHE_KEY))->toBeFalse();
    $lock->release();
});

it('releases the build lock after a cold build', function () {
    $this->get('/sitemap.xml')->assertOk();

    $lock = Cache::lock(Sitemap::BUILD_LOCK, 120);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('serves the last good copy at once while another process holds the build lock', function () {
    Sleep::fake();
    Product::factory()->create(['slug' => 'old-product']);
    $old = $this->get('/sitemap.xml')->assertOk()->getContent();
    Product::factory()->create(['slug' => 'new-product']); // flushes the fresh copy

    $held = Cache::lock(Sitemap::BUILD_LOCK, 120);
    expect($held->get())->toBeTrue();

    try {
        $this->get('/sitemap.xml')->assertOk()->assertContent($old);
    } finally {
        $held->release();
    }

    Sleep::assertNeverSlept();
});

it('answers 503 with Retry-After at once when the cache is cold, the lock is held and there is no copy', function () {
    Sleep::fake();
    $held = Cache::lock(Sitemap::BUILD_LOCK, 120);
    expect($held->get())->toBeTrue();

    try {
        $this->get('/sitemap.xml')->assertStatus(503)->assertHeader('Retry-After');
    } finally {
        $held->release();
    }

    Sleep::assertNeverSlept();
});

it('throttles the public sitemap to 60 requests a minute per IP', function () {
    $this->get('/sitemap.xml')->assertOk();

    foreach (range(2, 60) as $ignored) {
        $this->get('/sitemap.xml')->assertOk();
    }

    $this->get('/sitemap.xml')->assertStatus(429);
});

it('keeps serving the old document during a refresh', function () {
    $admin = staffUser();
    Product::factory()->create(['slug' => 'old-product']);
    $old = app(Sitemap::class)->xml();
    $during = null;

    $sitemap = new class extends Sitemap
    {
        public ?Closure $whileBuilding = null;

        public function generate(): array
        {
            ($this->whileBuilding)();

            return parent::generate();
        }
    };
    $sitemap->whileBuilding = function () use (&$during): void {
        $during = (new Sitemap)->xml();
    };

    (new RefreshSitemap($sitemap))->handle();

    expect($during)->toBe($old);
});

it('does not store a build that started before a flush', function () {
    $sitemap = app(Sitemap::class);
    $first = $sitemap->store($sitemap->generate());
    $generated = $sitemap->generate();

    Sitemap::flush();
    $returned = $sitemap->store($generated);

    expect($returned['xml'])->toBe($generated['xml'])
        ->and(Cache::has(Sitemap::CACHE_KEY))->toBeFalse()
        ->and(Cache::has(Sitemap::URLS_KEY))->toBeFalse()
        ->and(Cache::get(Sitemap::LAST_KEY)['generated_at'])->toBe($first['generated_at']);
});

it('counts the URLs per type for a factory dataset', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->child($root)->create();
    Brand::factory()->create()->products()->save(Product::factory()->inCategory($child)->make());
    Product::factory()->count(2)->inCategory($child)->create();
    Product::factory()->inactive()->create();
    NewsPost::factory()->create();
    NewsPost::factory()->article()->create();
    NewsPost::factory()->article()->create();
    Page::factory()->create();

    $counts = app(Sitemap::class)->generate()['counts'];

    expect($counts['products'])->toBe(3 * Product::query()->visible()->count())
        ->and($counts['categories'])->toBe(3 * count(CategoryTree::visibleIds()))
        ->and($counts['brands'])->toBe(3 * Brand::query()->listed()->count())
        ->and($counts['news'])->toBe(3)
        ->and($counts['articles'])->toBe(6)
        ->and($counts['pages'])->toBe(3)
        ->and($counts['other'])->toBeGreaterThanOrEqual(3 + 3);

    Livewire::actingAs(staffUser())->test(SitemapScreen::class);
    app(Sitemap::class)->xml();
    Livewire::actingAs(staffUser())->test(SitemapScreen::class)
        ->assertSeeHtml('data-sitemap-count="products"')
        ->assertSeeInOrder(['data-sitemap-count="products"', (string) $counts['products']]);
});

it('searches URLs server-side and paginates by 50', function () {
    Product::factory()->count(20)->create();
    Product::factory()->create(['slug' => 'needle-zz']);
    app(Sitemap::class)->xml();
    $admin = staffUser();

    $component = Livewire::actingAs($admin)->test(SitemapScreen::class);
    $total = count(app(Sitemap::class)->urls());

    expect($total)->toBeGreaterThan(50);
    expect($component->instance()->urls->count())->toBe(50)
        ->and($component->instance()->urls->total())->toBe($total);

    $component->set('search', 'NEEDLE-zz')
        ->assertSeeHtml('data-sitemap-row')
        ->assertSee('/uz/product/needle-zz')
        ->assertDontSee('/uz/catalog');

    expect($component->instance()->urls->total())->toBe(3);

    $component->set('search', str_repeat('a', 500));
    expect(mb_strlen($component->get('search')))->toBe(200)
        ->and($component->instance()->urls->total())->toBe(0);

    $component->set('search', '')->call('gotoPage', 2);
    expect($component->instance()->urls->count())->toBe(50);
});

it('escapes the raw XML view and caps it at 200 lines', function () {
    Product::factory()->count(40)->create();
    $sitemap = app(Sitemap::class);
    $sitemap->store(['xml' => '<?xml version="1.0"?><urlset><url><loc>https://x.test/<script>alert(1)</script></loc></url>'.str_repeat('<url><loc>a</loc></url>', 500).'</urlset>', 'urls' => [], 'counts' => array_fill_keys(Sitemap::TYPES, 0)]);

    $component = Livewire::actingAs(staffUser())->test(SitemapScreen::class)->call('showTab', 'xml');
    $raw = $component->instance()->rawXml;

    expect(substr_count($raw['text'], "\n") + 1)->toBeLessThanOrEqual(200)
        ->and($raw['truncated'])->toBeTrue();

    $component->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')->assertDontSeeHtml('<script>alert(1)</script>');
});

it('caps the raw XML at 64 KB', function () {
    app(Sitemap::class)->store(['xml' => str_repeat('x', 300_000), 'urls' => [], 'counts' => array_fill_keys(Sitemap::TYPES, 0)]);

    $raw = Livewire::actingAs(staffUser())->test(SitemapScreen::class)->instance()->rawXml;

    expect(strlen($raw['text']))->toBeLessThanOrEqual(65536)->and($raw['truncated'])->toBeTrue();
});

it('shows the status times in Asia/Tashkent', function () {
    $this->travelTo(now()->setDate(2026, 11, 2)->setTime(10, 0)->utc());
    app(Sitemap::class)->xml();

    Livewire::actingAs(staffUser())->test(SitemapScreen::class)->assertSet('tab', 'urls')
        ->assertSeeHtml('data-sitemap-generated')
        ->assertSee('02.11.2026 15:00');
});

it('returns 404 on the public sitemap while indexing is closed, even with both cache keys filled', function () {
    app(Sitemap::class)->xml();
    expect(Cache::has(Sitemap::CACHE_KEY))->toBeTrue()->and(Cache::has(Sitemap::LAST_KEY))->toBeTrue();

    openSearchIndexing(false);

    $this->get('/sitemap.xml')->assertNotFound();
});

it('keeps the admin preview and the refresh working while indexing is closed', function (Role $role) {
    openSearchIndexing(false);
    $user = staffUser($role);
    Product::factory()->create();

    Livewire::actingAs($user)->test(SitemapScreen::class)
        ->assertOk()
        ->call('refresh')
        ->assertDispatched('toast', title: __('sitemap.refreshed'), body: __('sitemap.refreshed_body', ['count' => array_sum(app(Sitemap::class)->payload()['counts'])]))
        ->assertSeeHtml('data-sitemap-state="ready"');

    $this->get('/sitemap.xml')->assertNotFound();
})->with([
    'admin' => [Role::Admin],
    'content manager' => [Role::ContentManager],
]);
