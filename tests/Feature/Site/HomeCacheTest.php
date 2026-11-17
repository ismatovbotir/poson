<?php

use App\Actions\Banners\MoveBanner;
use App\Actions\Catalog\ApplyCategoryIcons;
use App\Actions\Catalog\PublishSeededProducts;
use App\Actions\Catalog\ReorderCategory;
use App\Actions\Catalog\UpdateProductImages;
use App\Actions\Faq\MoveFaqItem;
use App\Actions\Mcp\CreateMcpFaqDraft;
use App\Actions\Mcp\UnpublishMcpPost;
use App\Console\Commands\ClearHomeCache;
use App\Data\Site\ProductCard;
use App\Livewire\Site\LeadForm;
use App\Models\ApiToken;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\McpCall;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\CompanySettings;
use App\Support\Site\HomeCache;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Plan 34: the home page data cache. One test group per security condition (HC-n).
 * The array store serializes here, like a database or Redis store would, so a
 * cached object would not survive the round trip.
 */
beforeEach(function () {
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');
    Cache::flush();
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * The home cache keys now in the store.
 *
 * @return list<string>
 */
function homeCacheKeys(): array
{
    return array_values(array_filter(
        array_keys((fn () => $this->storage)->call(Cache::getStore())),
        fn (string $key): bool => str_starts_with($key, 'home:') && $key !== HomeCache::VERSION_KEY,
    ));
}

describe('HC-1 / HC-7 key and locale', function () {
    it('keys the payload by locale, version and deploy id, one entry per locale', function () {
        Category::factory()->create(['name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
        Product::factory()->featured()->create();

        $this->get('/ru')->assertOk()->assertSee('Сканеры');
        $this->get('/uz')->assertOk()->assertSee('Skanerlar');

        $expected = ['home:ru:s'.HomeCache::SCHEMA.':v'.HomeCache::version().':'.HomeCache::deployId(), 'home:uz:s'.HomeCache::SCHEMA.':v'.HomeCache::version().':'.HomeCache::deployId()];

        expect(homeCacheKeys())->toEqualCanonicalizing($expected)
            ->and(HomeCache::key('en'))->toBe('home:en:s'.HomeCache::SCHEMA.':v'.HomeCache::version().':'.HomeCache::deployId())
            ->and(Cache::get($expected[0])['categories'][0]['name'])->toBe('Сканеры')
            ->and(Cache::get($expected[1])['categories'][0]['name'])->toBe('Skanerlar');
    });

    it('does not show another locale\'s cached text on a hit', function () {
        Category::factory()->create(['name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);

        $this->get('/ru')->assertOk();
        $this->get('/en')->assertOk()->assertSee('Scanners')->assertDontSee('Сканеры');
    });
});

describe('HC-2 plain arrays', function () {
    it('stores only scalars and arrays and serves the page from them', function () {
        Product::factory()->featured()->create(['price' => 125_000_000]);
        Brand::factory()->create();
        NewsPost::factory()->published()->create();

        $this->get('/uz')->assertOk();
        $payload = Cache::get(homeCacheKeys()[0]);

        $objects = 0;
        array_walk_recursive($payload, function ($value) use (&$objects): void {
            $objects += is_object($value) ? 1 : 0;
        });

        expect($payload)->toBeArray()->and($objects)->toBe(0)
            ->and($payload['products'])->toHaveCount(1)
            ->and($payload['products'][0])->toBeArray();

        $this->get('/uz')->assertOk()->assertSee('data-price', false);
    });

    it('round-trips every DTO through toArray() and fromArray()', function () {
        Product::factory()->featured()->create();
        $this->get('/uz')->assertOk();
        $payload = Cache::get(homeCacheKeys()[0]);

        $card = ProductCard::fromArray($payload['products'][0]);

        expect($card->toArray())->toBe($payload['products'][0]);
    });
});

describe('HC-3 TTL', function () {
    it('lives 4 hours without a boundary', function () {
        $this->freezeTime();

        expect(HomeCache::expiry()->equalTo(now()->addSeconds(HomeCache::MAX_TTL)))->toBeTrue();
    });

    it('is cut at the next banner boundary and the next scheduled news post', function () {
        $this->freezeTime();
        Banner::factory()->withImages()->scheduled(now()->addMinutes(30))->create();

        expect(HomeCache::expiry()->getTimestamp())->toBe(now()->addMinutes(30)->getTimestamp());

        NewsPost::factory()->scheduled(now()->addMinutes(10))->create();
        Cache::forget('site.news.v2');
        app()->forgetInstance('site.news.memo');

        expect(HomeCache::expiry()->getTimestamp())->toBe(now()->addMinutes(10)->getTimestamp());
    });

    it('never exceeds 4 hours even for a distant boundary', function () {
        Banner::factory()->withImages()->scheduled(now()->addDays(2))->create();

        expect(HomeCache::expiry()->lessThanOrEqualTo(now()->addSeconds(HomeCache::MAX_TTL)))->toBeTrue();
    });
});

describe('HC-4 every write path bumps the version', function () {
    it('bumps on save, delete, restore and force delete of the home models', function (Closure $make) {
        $model = $make();
        $version = HomeCache::version();

        $model->touch();
        expect(HomeCache::version())->not->toBe($version);

        $version = HomeCache::version();
        $model->delete();
        expect(HomeCache::version())->not->toBe($version);

        if (method_exists($model, 'restore')) {
            $version = HomeCache::version();
            $model->restore();
            expect(HomeCache::version())->not->toBe($version);

            $version = HomeCache::version();
            $model->forceDelete();
            expect(HomeCache::version())->not->toBe($version);
        }
    })->with([
        'product' => [fn () => Product::factory()->create()],
        'category' => [fn () => Category::factory()->create()],
        'brand' => [fn () => Brand::factory()->create()],
        'banner' => [fn () => Banner::factory()->create()],
        'news post' => [fn () => NewsPost::factory()->create()],
        'faq item' => [fn () => FaqItem::factory()->create()],
    ]);

    it('bumps when a media row changes or a conversion finishes', function () {
        $product = Product::factory()->create();
        $media = Media::query()->create([
            'model_type' => $product->getMorphClass(), 'model_id' => $product->id, 'uuid' => (string) str()->uuid(),
            'collection_name' => 'images', 'name' => 'a', 'file_name' => 'a.jpg', 'disk' => 'public', 'conversions_disk' => 'public',
            'size' => 1, 'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
        ]);

        $version = HomeCache::version();
        $media->update(['name' => 'b']);
        expect(HomeCache::version())->not->toBe($version);

        $version = HomeCache::version();
        event(new ConversionHasBeenCompletedEvent($media, new Conversion('thumb')));
        expect(HomeCache::version())->not->toBe($version);

        $version = HomeCache::version();
        $media->delete();
        expect(HomeCache::version())->not->toBe($version);
    });

    it('bumps from the mass-update actions that skip model events', function (Closure $prepare) {
        $act = $prepare();
        $version = HomeCache::version();

        $act();

        expect(HomeCache::version())->not->toBe($version);
    })->with([
        'MoveBanner' => [function () {
            $banners = Banner::factory()->count(2)->sequence(['sort' => 1], ['sort' => 2])->create();

            return fn () => app(MoveBanner::class)->handle($banners[1], -1);
        }],
        'MoveFaqItem' => [function () {
            $items = FaqItem::factory()->count(2)->create();

            return fn () => app(MoveFaqItem::class)->handle($items[1], -1);
        }],
        'ReorderCategory' => [function () {
            $categories = Category::factory()->count(2)->create();

            return fn () => app(ReorderCategory::class)->handle($categories[1], 0);
        }],
        'ApplyCategoryIcons' => [function () {
            Category::factory()->create(['slug' => 'scanners', 'icon' => 'scan']);

            return fn () => app(ApplyCategoryIcons::class)->handle();
        }],
        'company settings flush' => [fn () => fn () => CompanySettings::flush()],
        'MCP FAQ draft' => [function () {
            [$ceo] = mcpHolder();

            return fn () => app(CreateMcpFaqDraft::class)->handle($ceo, ['question' => ['uz' => 'Q?'], 'answer' => ['uz' => 'A.']]);
        }],
        'MCP unpublish post' => [function () {
            [$ceo] = mcpHolder();
            $call = McpCall::factory()->for($ceo)->publishOf(NewsPost::factory()->create())->create();

            return fn () => app(UnpublishMcpPost::class)->handle($call->id, $ceo);
        }],
    ]);

    it('bumps after a 1C price sync', function () {
        $token = fakeApiTokenValue();
        ApiToken::factory()->withPlainText($token)->create();
        Product::factory()->featured()->create(['sku' => 'ZD421', 'price' => 100_00]);
        $this->get('/uz')->assertOk();
        $version = HomeCache::version();

        postPrices(['items' => [priceItem('ZD421', 1500)]], $token)->assertOk();

        expect(HomeCache::version())->not->toBe($version);
    });
});

describe('review fixes', function () {
    it('bumps when product images are reordered and when seeded products are (un)published', function () {
        $product = Product::factory()->create();
        $ids = collect(range(1, 2))->map(fn (int $i) => Media::query()->create([
            'model_type' => $product->getMorphClass(), 'model_id' => $product->id, 'uuid' => (string) str()->uuid(),
            'collection_name' => Product::IMAGES, 'name' => 'a'.$i, 'file_name' => 'a'.$i.'.jpg', 'disk' => 'public', 'conversions_disk' => 'public',
            'size' => 1, 'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
        ])->id);

        $version = HomeCache::version();
        app(UpdateProductImages::class)->move($product, $ids[1], 0);
        expect(HomeCache::version())->not->toBe($version);

        $version = HomeCache::version();
        PublishSeededProducts::flushCaches();
        expect(HomeCache::version())->not->toBe($version);
    });

    it('rebuilds instead of failing when the cached payload has an old shape', function () {
        Product::factory()->featured()->create();
        $this->get('/uz')->assertOk();

        $key = homeCacheKeys()[0];
        $payload = Cache::get($key);
        $payload['products'][0]['stock_status'] = 'gone';
        Cache::put($key, $payload, 600);
        Livewire::flushState();

        $this->get('/uz')->assertOk()->assertSee('data-price', false);
        expect(Cache::get($key)['products'][0]['stock_status'])->not->toBe('gone');
    });

    it('builds the cached URLs on the canonical https root even on an http miss', function () {
        config(['app.url' => 'https://poson.test']);
        Category::factory()->create();

        $this->call('GET', '/uz', [], [], [], ['HTTPS' => 'off', 'HTTP_HOST' => 'poson.test'])->assertOk();
        $json = json_encode(Cache::get(homeCacheKeys()[0]), JSON_UNESCAPED_SLASHES);

        expect($json)->toContain('https://poson.test/uz/')->not->toContain('http://poson.test');
    });

    it('mixes APP_DEPLOY_ID into the deploy hash and keeps the cart switch in it', function () {
        $base = HomeCache::deployId();
        config(['app.deploy_id' => 'x']);
        $withId = HomeCache::deployId();
        config(['app.deploy_id' => 'x', 'site.cart_enabled' => ! config('site.cart_enabled')]);

        expect($withId)->not->toBe($base)->and(HomeCache::deployId())->not->toBe($withId);
    });
});

describe('HC-5 bump after commit', function () {
    it('changes nothing when the transaction rolls back and bumps once it commits', function () {
        $version = HomeCache::version();

        try {
            DB::transaction(function () {
                HomeCache::bump();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        expect(HomeCache::version())->toBe($version);

        DB::transaction(fn () => HomeCache::bump());

        expect(HomeCache::version())->not->toBe($version);
    });
});

describe('HC-6 deploy', function () {
    it('changes the key with the deploy id and by the clear command', function () {
        $key = HomeCache::key('uz');

        config(['app.deploy_id' => 'build-2']);
        expect(HomeCache::key('uz'))->not->toBe($key);

        config(['app.deploy_id' => null]);
        $version = HomeCache::version();
        $this->artisan('site:home-cache-clear')->assertSuccessful();

        expect(HomeCache::version())->not->toBe($version)
            ->and((new ClearHomeCache)->getDescription())->not->toBe('')
            ->and((new ClearHomeCache)->getHelp())->not->toBe('');
    });

    it('lists the cache commands with a schedule entry for the prune', function () {
        $names = collect(Artisan::all())->keys();
        $scheduled = collect(app(Schedule::class)->events())->map->command->implode(' ');

        expect($names)->toContain('site:home-cache-clear', 'cache:prune-expired')
            ->and($scheduled)->toContain('cache:prune-expired');
    });
});

describe('HC-8 JSON-LD', function () {
    it('keeps a </script> in a FAQ answer escaped on a cache hit', function () {
        FaqItem::factory()->create(['question' => ['uz' => 'Savol'], 'answer' => ['uz' => 'Javob </script><script>alert(1)</script>']]);

        $this->get('/uz')->assertOk();
        $html = $this->get('/uz')->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)</script>')
            ->and(substr_count($html, 'application/ld+json'))->toBeGreaterThanOrEqual(3)
            ->and($html)->toContain('\u003C/script\u003E');
    });
});

describe('HC-9 session data stays outside', function () {
    it('gives every session its own CSRF token on a cache hit', function () {
        $this->get('/uz')->assertOk();

        Livewire::flushState();
        $first = $this->withSession(['_token' => 'tokenOfSessionOne'])->get('/uz')->assertOk()->getContent();
        Livewire::flushState();
        $second = $this->withSession(['_token' => 'tokenOfSessionTwo'])->get('/uz')->assertOk()->getContent();

        expect($first)->toContain('data-csrf="tokenOfSessionOne"')->not->toContain('tokenOfSessionTwo')
            ->and($second)->toContain('data-csrf="tokenOfSessionTwo"')->not->toContain('tokenOfSessionOne')
            ->and(count(homeCacheKeys()))->toBe(1);
    });

    it('sets no public Cache-Control and keeps the lead form on a hit', function () {
        $this->get('/uz')->assertOk();

        $response = $this->get('/uz')->assertOk()->assertSeeLivewire('site.lead-form');

        expect($response->headers->get('Cache-Control'))->not->toContain('public');
    });

    it('still submits and throttles the lead form on a cache hit', function () {
        $this->get('/uz')->assertOk();
        $this->get('/uz')->assertOk();

        $submit = function (int $i) {
            $component = Livewire::test(LeadForm::class)
                ->set('name', 'Aziz')->set('phone', '+99890'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT))->set('company', 'OOO');
            test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

            return $component->call('submit');
        };

        $submit(1)->assertHasNoErrors()->assertSet('sent', true);

        foreach (range(2, LeadForm::RATE_LIMIT) as $i) {
            $submit($i)->assertSet('sent', true);
        }

        $submit(99)->assertHasErrors(['form'])->assertSet('sent', false);
    });
});

describe('HC-11 prune', function () {
    it('deletes only the expired rows of the database cache table', function () {
        config(['cache.default' => 'database']);
        DB::table('cache')->insert([
            ['key' => 'old', 'value' => 's:1:"a";', 'expiration' => now()->subHour()->getTimestamp()],
            ['key' => 'live', 'value' => 's:1:"a";', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $this->artisan('cache:prune-expired')->assertSuccessful();

        expect(DB::table('cache')->pluck('key')->all())->toBe(['live']);
    });

    it('prunes more rows than one chunk', function () {
        config(['cache.default' => 'database']);
        $rows = [];
        foreach (range(1, 2100) as $i) {
            $rows[] = ['key' => 'old'.$i, 'value' => 'x', 'expiration' => now()->subHour()->getTimestamp()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('cache')->insert($chunk);
        }

        $this->artisan('cache:prune-expired')->assertSuccessful();

        expect(DB::table('cache')->count())->toBe(0);
    });

    it('does nothing for a non-database store', function () {
        $this->artisan('cache:prune-expired')->assertSuccessful();
    });
});

describe('query budget', function () {
    // On the database store a hit is the version read + the payload read (2 queries) plus the visit insert;
    // the array store used here makes the cache reads free, so only the insert remains.
    it('runs at most 2 queries on a cache hit (the analytics visit insert) and fewer than on a miss', function () {
        Category::factory()->create();
        Product::factory()->featured()->create();
        Brand::factory()->create();

        DB::enableQueryLog();
        $this->get('/uz')->assertOk();
        $miss = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->get('/uz')->assertOk();
        $hit = count(DB::getQueryLog());

        expect($hit)->toBeLessThan($miss)->and($hit)->toBeLessThanOrEqual(2);
    });
});
