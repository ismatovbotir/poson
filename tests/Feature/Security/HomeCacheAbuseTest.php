<?php

use App\Models\ApiToken;
use App\Models\Banner;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\Site\HomeCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Plan 34 §3 abuse cases of the home page data cache. The array store
 * serializes, like the database store in production.
 */
beforeEach(function () {
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');
    Cache::flush();
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * @return list<string>
 */
function abuseHomeKeys(): array
{
    return array_values(array_filter(
        array_keys((fn () => $this->storage)->call(Cache::getStore())),
        fn (string $key): bool => str_starts_with($key, 'home:') && $key !== HomeCache::VERSION_KEY,
    ));
}

it('never caches a poisoned Host or X-Forwarded-Host in the payload', function () {
    Category::factory()->create();
    Product::factory()->featured()->create();

    $this->call('GET', '/uz', [], [], [], [
        'HTTP_HOST' => 'evil.example',
        'HTTP_X_FORWARDED_HOST' => 'evil.example',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->assertOk();

    $payload = Cache::get(abuseHomeKeys()[0]);

    expect(json_encode($payload, JSON_UNESCAPED_SLASHES))->not->toContain('evil.example')
        ->and(json_encode($payload, JSON_UNESCAPED_SLASHES))->toContain(rtrim((string) config('app.url'), '/').'/uz/');
});

it('writes no cache key for an unknown or malformed locale', function (string $path) {
    $response = $this->get($path);

    expect($response->status())->not->toBe(200)->and(abuseHomeKeys())->toBe([]);
})->with(['/xx', '/..%2f', '/uz%00', '/%2e%2e', '/UZ', '/uz%2f..%2fru']);

it('shows a hidden, deleted or repriced product on the very next request', function () {
    $product = Product::factory()->featured()->create(['price' => 100_000_00, 'name' => ['uz' => 'ZetaScan 9000']]);

    $this->get('/uz')->assertSee('ZetaScan 9000');
    $this->get('/uz')->assertSee('ZetaScan 9000');

    $product->update(['price' => 200_000_00]);
    $this->get('/uz')->assertSee('200'."\u{a0}".'000');

    $product->update(['is_active' => false]);
    $this->get('/uz')->assertDontSee('ZetaScan 9000');

    $product->update(['is_active' => true]);
    $this->get('/uz')->assertSee('ZetaScan 9000');

    $product->delete();
    $this->get('/uz')->assertDontSee('ZetaScan 9000');

    $product->restore();
    $this->get('/uz')->assertSee('ZetaScan 9000');

    $product->forceDelete();
    $this->get('/uz')->assertDontSee('ZetaScan 9000');
});

it('shows a 1C price change on the next request', function () {
    $token = fakeApiTokenValue();
    ApiToken::factory()->withPlainText($token)->create();
    Product::factory()->featured()->create(['sku' => 'ZD421', 'price' => 100_000_00]);

    $this->get('/uz')->assertSee('100'."\u{a0}".'000');

    postPrices(['items' => [priceItem('ZD421', 300_000)]], $token)->assertOk();

    $this->get('/uz')->assertSee('300'."\u{a0}".'000')->assertDontSee('100'."\u{a0}".'000');
});

it('hides a hidden category and its products at once', function () {
    $category = Category::factory()->create(['name' => ['uz' => 'Yashiriladigan toifa']]);
    Product::factory()->featured()->inCategory($category)->create();

    $this->get('/uz')->assertSee('Yashiriladigan toifa');

    $category->update(['is_active' => false]);

    $this->get('/uz')->assertDontSee('Yashiriladigan toifa');
});

it('lets an expiring banner disappear and a scheduled one appear on time', function () {
    Banner::factory()->withImages()->expired(now()->addHour())->create(['title' => ['uz' => 'Tugaydigan banner']]);
    Banner::factory()->withImages()->scheduled(now()->addHours(2))->create(['title' => ['uz' => 'Boshlanadigan banner']]);

    $this->get('/uz')->assertSee('Tugaydigan banner')->assertDontSee('Boshlanadigan banner');
    $this->get('/uz')->assertSee('Tugaydigan banner');

    $this->travel(90)->minutes();
    $this->get('/uz')->assertDontSee('Tugaydigan banner')->assertDontSee('Boshlanadigan banner');

    $this->travel(60)->minutes();
    $this->get('/uz')->assertSee('Boshlanadigan banner');
});

it('shows a scheduled news post once its date passes, without a bump', function () {
    NewsPost::factory()->published()->create(['title' => ['uz' => 'Eski yangilik']]);
    NewsPost::factory()->scheduled(now()->addHour())->create(['title' => ['uz' => 'Rejalashtirilgan yangilik']]);

    $this->get('/uz')->assertSee('Eski yangilik')->assertDontSee('Rejalashtirilgan yangilik');
    $this->get('/uz')->assertDontSee('Rejalashtirilgan yangilik');

    $this->travel(61)->minutes();

    $this->get('/uz')->assertSee('Rejalashtirilgan yangilik');
});

it('leaves the version unchanged when the writing transaction rolls back', function () {
    $product = Product::factory()->featured()->create();
    $this->get('/uz')->assertOk();
    $version = HomeCache::version();

    try {
        DB::transaction(function () use ($product) {
            $product->update(['is_active' => false]);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(HomeCache::version())->toBe($version);
    $this->get('/uz')->assertSee($product->fresh()->name);
});

it('keeps objects, sessions and CSRF tokens out of the cached value', function () {
    Product::factory()->featured()->create();

    $this->get('/uz')->assertOk();
    $token = session()->token();
    $raw = serialize(Cache::get(abuseHomeKeys()[0]));

    expect($raw)->not->toMatch('/(^|[;{}])O:\d+:"/')
        ->and($raw)->not->toContain('__PHP_Incomplete_Class')
        ->and($raw)->not->toContain($token)
        ->and($raw)->not->toContain(session()->getId())
        ->and(strtolower($raw))->not->toContain('csrf')->not->toContain('xsrf');
});
