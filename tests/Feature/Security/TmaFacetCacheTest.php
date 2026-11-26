<?php

use App\Actions\Catalog\DeleteAttribute;
use App\Actions\Catalog\DeleteAttributeOption;
use App\Actions\Catalog\SaveAttribute;
use App\Actions\Catalog\SyncCategoryAttributes;
use App\Enums\AttributeType;
use App\Enums\Role;
use App\Livewire\Admin\Telegram\MiniApp;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\SettingsAudit;
use App\Rules\BrandSafeText;
use App\Support\Catalog\CatalogQuery;
use App\Support\Catalog\CategoryFacetCache;
use App\Support\Site\HomeCache;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

require_once __DIR__.'/../../Helpers/MiniApp.php';

/*
| Plan 45 C security sign-off: abuse cases 11-15 of the Mini App facet cache
| (MA-49 payload, MA-50 key and use, MA-51 bumps, MA-52 rebuild, MA-53
| schedule). In-process, array cache, test DB only, Http::fake only.
*/
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
    tmaSignIn(tmaUser());

    $this->category = Category::factory()->create(['slug' => 'abuse-cat']);
    $this->select = Attribute::factory()->select(['USB', 'RS232'])->create(['code' => 'iface']);
    $this->flag = Attribute::factory()->boolean()->create(['code' => 'wifi']);

    foreach ([$this->select, $this->flag] as $index => $attribute) {
        $this->category->attributes()->attach($attribute->id, ['is_required' => false, 'is_filterable' => true, 'sort' => $index + 1]);
    }

    $this->product = Product::factory()->inCategory($this->category)->create();
    ProductAttributeValue::factory()->for($this->product)->option($this->select->options()->orderBy('sort')->firstOrFail())->create(['attribute_id' => $this->select->id]);
    ProductAttributeValue::factory()->for($this->product)->boolean()->create(['attribute_id' => $this->flag->id]);
});

/**
 * The tma:facets keys read (hit or miss) and written while the callback runs.
 *
 * @return array{read: list<string>, written: list<string>}
 */
function facetCacheTraffic(callable $callback): array
{
    $traffic = ['read' => [], 'written' => []];
    $facet = fn (string $key): bool => str_starts_with($key, 'tma:facets:');

    Event::listen(CacheHit::class, function (CacheHit $event) use (&$traffic, $facet): void {
        if ($facet($event->key)) {
            $traffic['read'][] = $event->key;
        }
    });
    Event::listen(CacheMissed::class, function (CacheMissed $event) use (&$traffic, $facet): void {
        if ($facet($event->key)) {
            $traffic['read'][] = $event->key;
        }
    });
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$traffic, $facet): void {
        if ($facet($event->key)) {
            $traffic['written'][] = $event->key;
        }
    });

    $callback();

    return $traffic;
}

function assertPlain(mixed $value): void
{
    if (is_array($value)) {
        array_walk($value, fn (mixed $item) => assertPlain($item));

        return;
    }

    expect($value === null || is_scalar($value))->toBeTrue();
}

// 11
it('returns a real AttributeType on a hit, stores plain data only and gives the same output with the cache on and off (11)', function () {
    expect(config('cache.serializable_classes'))->toBeFalse();

    $live = (new CatalogQuery(category: $this->category))->facets();
    $miss = $this->get('/tma/uz/c/abuse-cat/filters')->assertOk();
    $stored = Cache::get(CategoryFacetCache::key($this->category->id, 'uz'));
    $hit = $this->get('/tma/uz/c/abuse-cat/filters')->assertOk();

    assertPlain($stored);

    expect($stored['attributes'][0]['type'])->toBe('select')
        ->and($hit->viewData('facets')['attributes'][0]['type'])->toBe(AttributeType::Select)
        ->and($hit->viewData('facets'))->toBe($live)
        ->and($hit->getContent())->toBe($miss->getContent());
});

it('rebuilds live and overwrites an entry that does not hydrate (11)', function (mixed $type) {
    $key = CategoryFacetCache::key($this->category->id, 'uz');
    $this->get('/tma/uz/c/abuse-cat/filters')->assertOk();
    $broken = Cache::get($key);
    $broken['attributes'][0]['type'] = $type;
    Cache::put($key, $broken, 60);

    $facets = $this->get('/tma/uz/c/abuse-cat/filters')->assertOk()->viewData('facets');

    expect($facets['attributes'][0]['type'])->toBe(AttributeType::Select)
        ->and(Cache::get($key)['attributes'][0]['type'])->toBe('select');
})->with([
    'unknown value' => ['bogus'],
    'missing' => [null],
    'an int' => [3],
    'an array' => [['select']],
]);

it('rebuilds live a payload of the wrong shape (11)', function (mixed $payload) {
    Cache::put(CategoryFacetCache::key($this->category->id, 'uz'), $payload, 60);

    expect($this->get('/tma/uz/c/abuse-cat/filters')->assertOk()->viewData('facets'))
        ->toBe((new CatalogQuery(category: $this->category))->facets());
})->with([
    'a string' => ['x'],
    'an empty array' => [[]],
    'no attributes' => [['brands' => [], 'stock' => [], 'price' => []]],
]);

// 12
it('never reads or writes a facet key for a forged locale, a hidden category or a filter request (12)', function (string $url) {
    $traffic = facetCacheTraffic(fn () => $this->get($url));

    expect($traffic)->toBe(['read' => [], 'written' => []]);
})->with([
    'unsupported locale' => ['/tma/xx/c/abuse-cat/filters'],
    'locale with a path' => ['/tma/uz%2F..%2Fru/c/abuse-cat/filters'],
    'm[] with zero active filters' => ['/tma/uz/c/abuse-cat/filters?m[iface]=any'],
    'm[] as a scalar' => ['/tma/uz/c/abuse-cat/filters?m=any'],
    'an active filter' => ['/tma/uz/c/abuse-cat/filters?f[wifi][]=1'],
]);

it('never reads or writes a facet key for a hidden category (12)', function () {
    $this->category->update(['is_active' => false]);

    $traffic = facetCacheTraffic(fn () => $this->get('/tma/uz/c/abuse-cat/filters')->assertNotFound());

    expect($traffic)->toBe(['read' => [], 'written' => []]);
});

it('keys only on the app locale and the bound category, whatever the Host or forwarded headers (12)', function () {
    $expected = CategoryFacetCache::key($this->category->id, 'ru');

    $traffic = facetCacheTraffic(fn () => $this->withHeaders([
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-Proto' => 'http',
        'Accept-Language' => 'en',
        'Host' => 'attacker.example',
    ])->get('/tma/ru/c/abuse-cat/filters?locale=en&category='.$this->category->id + 1));

    expect(array_values(array_unique($traffic['read'])))->toBe([$expected])
        ->and($traffic['written'])->toBe([$expected])
        ->and(json_encode(Cache::get($expected)))->not->toContain('evil.example')->not->toContain('attacker.example');
});

it('builds an unsupported locale every time without storing it (12)', function () {
    $builds = 0;
    $traffic = facetCacheTraffic(function () use (&$builds) {
        foreach ([1, 2] as $round) {
            CategoryFacetCache::remember($this->category, 'xx', function () use (&$builds): array {
                $builds++;

                return (new CatalogQuery(category: $this->category))->facets();
            });
        }
    });

    expect($builds)->toBe(2)->and($traffic)->toBe(['read' => [], 'written' => []]);
});

// 13
it('removes or relabels a facet entry while the cache is warm (13)', function (Closure $write, Closure $expect) {
    $this->get('/tma/uz/c/abuse-cat/filters')->assertOk();
    $before = CategoryFacetCache::key($this->category->id, 'uz');

    $write($this);

    expect(CategoryFacetCache::key($this->category->id, 'uz'))->not->toBe($before);

    $expect($this->get('/tma/uz/c/abuse-cat/filters')->assertOk()->viewData('facets'));
})->with([
    'attribute sync' => [
        fn ($test) => app(SyncCategoryAttributes::class)->handle($test->category, [['attribute_id' => $test->select->id, 'is_required' => false, 'is_filterable' => true]]),
        fn (array $facets) => expect(collect($facets['attributes'])->pluck('code')->all())->toBe(['iface']),
    ],
    'unfilterable change' => [
        fn ($test) => app(SyncCategoryAttributes::class)->handle($test->category, [
            ['attribute_id' => $test->select->id, 'is_required' => false, 'is_filterable' => true],
            ['attribute_id' => $test->flag->id, 'is_required' => false, 'is_filterable' => false],
        ]),
        fn (array $facets) => expect(collect($facets['attributes'])->pluck('code')->all())->toBe(['iface']),
    ],
    'attribute rename' => [
        fn ($test) => app(SaveAttribute::class)->handle($test->flag, [
            'code' => 'wifi', 'name' => ['uz' => 'Simsiz aloqa', 'ru' => null, 'en' => null], 'unit' => null,
            'type' => AttributeType::Boolean, 'is_filterable' => true, 'sort' => 2,
        ]),
        fn (array $facets) => expect(collect($facets['attributes'])->firstWhere('code', 'wifi')['name'])->toBe('Simsiz aloqa'),
    ],
    'attribute delete' => [
        function ($test) {
            $unused = Attribute::factory()->select(['A'])->create();
            $test->category->attributes()->attach($unused->id, ['is_required' => false, 'is_filterable' => true, 'sort' => 9]);
            $test->get('/tma/uz/c/abuse-cat/filters');
            app(DeleteAttribute::class)->handle($unused);
        },
        fn (array $facets) => expect(collect($facets['attributes'])->pluck('code')->all())->toBe(['iface', 'wifi']),
    ],
    'option delete' => [
        fn ($test) => app(DeleteAttributeOption::class)->handle($test->select->options()->whereNotIn('id', ProductAttributeValue::query()->whereNotNull('attribute_option_id')->pluck('attribute_option_id'))->firstOrFail()),
        fn (array $facets) => expect(collect($facets['attributes'])->firstWhere('code', 'iface')['options'])->toHaveCount(1),
    ],
    'product hide' => [
        fn ($test) => $test->product->update(['is_active' => false]),
        fn (array $facets) => expect($facets['attributes'])->toBe([])->and($facets['brands'])->toBe([]),
    ],
]);

// 14
it('forbids non-Admin roles the page and rebuildCatalogCache (14)', function (Role $role) {
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.telegram.mini-app'))->assertForbidden();
    Livewire::actingAs($user)->test(MiniApp::class)->assertForbidden();

    $component = Livewire::actingAs(staffUser())->test(MiniApp::class);
    $this->actingAs($user);
    $component->call('rebuildCatalogCache')->assertForbidden();

    expect(CategoryFacetCache::warmedAt())->toBeNull()
        ->and(RateLimiter::attempts('tma-warm:'.$user->id))->toBe(0);
})->with([
    'content manager' => Role::ContentManager,
    'sales manager' => Role::SalesManager,
    'service engineer' => Role::ServiceEngineer,
]);

it('lets an Admin rebuild 3 times in 10 minutes and refuses the 4th, with no HTTP call, job or audit (14)', function () {
    Http::fake();
    Queue::fake();
    $admin = staffUser();
    $component = Livewire::actingAs($admin)->test(MiniApp::class);
    $audits = SettingsAudit::query()->count();

    foreach ([1, 2, 3] as $round) {
        $version = HomeCache::version();
        $component->call('rebuildCatalogCache')->assertDispatched('toast', type: 'success');

        expect(HomeCache::version())->not->toBe($version)
            ->and(Cache::get(CategoryFacetCache::key($this->category->id, 'uz')))->toBeArray();
    }

    $version = HomeCache::version();
    $warmed = CategoryFacetCache::warmedAt();
    $component->call('rebuildCatalogCache')->assertDispatched('toast', type: 'error');

    expect(HomeCache::version())->toBe($version)
        ->and(CategoryFacetCache::warmedAt())->toBe($warmed)
        ->and(SettingsAudit::query()->count())->toBe($audits);

    $this->travel(11)->minutes();
    $component->call('rebuildCatalogCache')->assertDispatched('toast', type: 'success');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('takes no arguments and gives the slot back when the shared lock is held (14)', function () {
    $method = new ReflectionMethod(MiniApp::class, 'rebuildCatalogCache');
    expect($method->getNumberOfParameters())->toBe(0)->and($method->isPublic())->toBeTrue();

    $admin = staffUser();
    $lock = CategoryFacetCache::lock();
    $lock->get();

    Livewire::actingAs($admin)->test(MiniApp::class)
        ->call('rebuildCatalogCache', 99, 'ru')
        ->assertDispatched('toast', type: 'error');

    expect(CategoryFacetCache::warmedAt())->toBeNull()
        ->and(RateLimiter::attempts('tma-warm:'.$admin->id))->toBe(0);

    $lock->release();
});

it('shows the warmed line to an Admin with copy in every locale that passes BrandSafeText (14)', function () {
    $this->artisan('tma:warm-catalog')->assertSuccessful();

    $this->actingAs(staffUser())->get(route('admin.telegram.mini-app'))->assertOk()
        ->assertSee('data-catalog-warmed="1"', false)
        ->assertSee('data-action="rebuild-catalog-cache"', false);

    foreach (['uz', 'ru', 'en'] as $locale) {
        foreach ((array) __('admin.telegram_mini_app.catalog_cache', [], $locale) as $key => $value) {
            expect(BrandSafeText::violates((string) $value))->toBeFalse("{$locale}.{$key}");

            if ($locale === 'uz') {
                expect(preg_match("/[a-z]['‘’][a-z]/iu", (string) $value))->toBe(0, "uz.{$key}: use ʻ / ʼ");
            }
        }

        expect(array_keys((array) __('admin.telegram_mini_app.catalog_cache', [], $locale)))
            ->toBe(array_keys((array) __('admin.telegram_mini_app.catalog_cache', [], 'en')));
    }
});

// 15
it('keeps queue:work the last scheduled event, after the warm (15)', function () {
    $events = collect(app(Schedule::class)->events())->values();

    expect($events->last()->description)->toBe('queue-worker')
        ->and($events->search(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'tma:warm-catalog')))
        ->toBeLessThan($events->count() - 1);
});
