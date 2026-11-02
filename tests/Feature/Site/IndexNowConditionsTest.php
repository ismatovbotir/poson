<?php

use App\Actions\Api\SyncPrices;
use App\Actions\Settings\ClearSecret;
use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Actions\Settings\TestIntegrationConnection;
use App\Events\PriceSyncCompleted;
use App\Jobs\SubmitIndexNow;
use App\Models\Brand;
use App\Models\Category;
use App\Models\IntegrationSecret;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Secrets;
use App\Support\Site\IndexNow;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;

const IN_KEY = 'fedcba9876543210fedcba9876543210';

function enableIndexNow(): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.url' => 'https://poson.example.uz']);
    openSearchIndexing();
    app(SetSecret::class)->handle('indexnow.key', IN_KEY, null, null);
    app(SaveIntegrationSettings::class)->handle(['indexnow.enabled' => true], null, null);
}

/**
 * Break exactly one gate condition of an enabled IndexNow.
 */
function breakIndexNowGate(string $case): void
{
    match ($case) {
        'indexing closed' => openSearchIndexing(false),
        'not production' => app()->detectEnvironment(fn (): string => 'local'),
        'http app url' => config(['app.url' => 'http://poson.example.uz']),
        'localhost app url' => config(['app.url' => 'https://localhost']),
        'disabled' => app(SaveIntegrationSettings::class)->handle(['indexnow.enabled' => false], null, null),
        'empty key' => app(ClearSecret::class)->handle('indexnow.key', null, null),
        'invalid key' => storeInvalidIndexNowKey(),
    };
}

function storeInvalidIndexNowKey(): void
{
    IntegrationSecret::query()->updateOrCreate(['key' => 'indexnow.key'], ['value' => 'too-short', 'hint' => 'hort']);
    Secrets::flush();
}

/**
 * Seed the snapshot, apply the change, flush again and return the queued URLs.
 *
 * @return list<string>
 */
function indexNowBatch(Closure $change): array
{
    Queue::fake();
    Artisan::call('indexnow:flush');
    $change();
    Artisan::call('indexnow:flush');

    return Queue::pushed(SubmitIndexNow::class)->flatMap(fn (SubmitIndexNow $job): array => $job->urls)->values()->all();
}

function urlsWith(array $urls, string $needle): array
{
    return array_values(array_filter($urls, fn (string $url): bool => str_contains($url, $needle)));
}

const IN_GATE_CASES = ['indexing closed', 'not production', 'http app url', 'localhost app url', 'disabled', 'empty key', 'invalid key'];

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

describe('gate (IN-S1, IN-S2)', function () {
    it('is inactive when a gate condition fails: :dataset', function (string $case) {
        enableIndexNow();
        expect(IndexNow::isActive())->toBeTrue();

        breakIndexNowGate($case);

        expect(IndexNow::isActive())->toBeFalse();
    })->with(IN_GATE_CASES);

    it('records nothing, sends nothing and hides the key URL when the gate is off: :dataset', function (string $case) {
        enableIndexNow();
        breakIndexNowGate($case);

        Product::factory()->create();
        Category::factory()->create();
        Brand::factory()->create();
        Page::factory()->create();
        NewsPost::factory()->published()->create();
        expect(IndexNow::pendingCount())->toBe(0);

        (new SubmitIndexNow(['https://poson.example.uz/uz']))->handle();
        $result = app(TestIntegrationConnection::class)->handle(User::factory()->create(), 'indexnow', null);

        Http::assertNothingSent();
        expect($result->ok)->toBeFalse();
        expect($this->get('https://poson.example.uz/'.IN_KEY.'.txt')->getStatusCode())->not->toBe(200);
    })->with(IN_GATE_CASES);
});

describe('batch content (IN-S11)', function () {
    it('never contains draft or unpublished records', function () {
        enableIndexNow();

        $urls = indexNowBatch(function (): void {
            Product::factory()->inactive()->create(['slug' => 'hidden-product']);
            Page::factory()->inactive()->create(['slug' => 'hidden-page']);
            NewsPost::factory()->draft()->create(['slug' => 'hidden-draft']);
            NewsPost::factory()->scheduled()->create(['slug' => 'hidden-scheduled']);
        });

        expect(implode("\n", $urls))->not->toContain('hidden-product')
            ->not->toContain('hidden-page')
            ->not->toContain('hidden-draft')
            ->not->toContain('hidden-scheduled');
    });

    it('lists a news post only in its own locales', function () {
        enableIndexNow();

        $urls = indexNowBatch(function (): void {
            NewsPost::factory()->published()->untranslated('ru', 'en')->create(['slug' => 'only-uz-news']);
        });

        $own = urlsWith($urls, 'only-uz-news');

        expect($own)->toHaveCount(1)
            ->and($own[0])->toEndWith('/uz/news/only-uz-news');
    });

    it('contains old and new URLs when a slug changes', function () {
        enableIndexNow();
        $product = Product::factory()->create(['slug' => 'old-slug-item']);

        $urls = indexNowBatch(fn () => $product->update(['slug' => 'new-slug-item']));

        expect(urlsWith($urls, 'old-slug-item'))->toHaveCount(count(config('app.supported_locales')))
            ->and(urlsWith($urls, 'new-slug-item'))->toHaveCount(count(config('app.supported_locales')));
    });

    it('contains the removed URLs when a record is unpublished', function (string $kind) {
        enableIndexNow();
        $record = match ($kind) {
            'product' => Product::factory()->create(['slug' => 'going-away']),
            'page' => Page::factory()->create(['slug' => 'going-away']),
            'news' => NewsPost::factory()->published()->create(['slug' => 'going-away']),
        };

        $urls = indexNowBatch(function () use ($record, $kind): void {
            match ($kind) {
                'product' => $record->update(['is_active' => false]),
                'page' => $record->update(['is_active' => false]),
                'news' => $record->update(['status' => 'draft', 'published_at' => null]),
            };
        });

        expect(urlsWith($urls, 'going-away'))->not->toBeEmpty();
    })->with(['product', 'page', 'news']);
});

describe('recorder hooks (IN-S12, IN-S16)', function () {
    it('records pages and news posts through the model hooks', function () {
        enableIndexNow();
        $page = Page::factory()->create();
        $post = NewsPost::factory()->published()->create();

        expect(IndexNow::pending()['page'])->toContain($page->id)
            ->and(IndexNow::pending()['news_post'])->toContain($post->id);
    });

    it('never throws when the recorder lock times out', function () {
        enableIndexNow();
        $product = Product::factory()->create();
        Cache::forget(IndexNow::PENDING_KEY);

        $timeoutLock = new class(new ArrayStore, IndexNow::LOCK_KEY, 10) extends ArrayLock
        {
            public function block($seconds, $callback = null): never
            {
                throw new LockTimeoutException;
            }
        };
        $store = new class($timeoutLock) extends ArrayStore
        {
            public function __construct(protected ArrayLock $timeoutLock)
            {
                parent::__construct();
            }

            public function lock($name, $seconds = 0, $owner = null): ArrayLock
            {
                return $this->timeoutLock;
            }
        };
        Cache::extend('locktimeout', fn () => Cache::repository($store));
        config(['cache.stores.locktimeout' => ['driver' => 'locktimeout']]);
        Cache::setDefaultDriver('locktimeout');

        Exceptions::fake();

        $product->update(['slug' => 'lock-timeout-slug']);

        Exceptions::assertReported(LockTimeoutException::class);
        expect(IndexNow::pendingCount())->toBe(0);
    });

    it('adds no ids on the SyncPrices path', function () {
        enableIndexNow();
        Event::fake([PriceSyncCompleted::class]);
        $product = Product::factory()->create(['sku' => 'IN-SKU-1']);
        Cache::forget(IndexNow::PENDING_KEY);

        $payload = json_decode(json_encode(['items' => [['code' => 'IN-SKU-1', 'retail' => 150000, 'dealer' => 140000, 'wholesale' => 130000]]]));
        $result = app(SyncPrices::class)->handle($payload, null, '127.0.0.1');

        expect($result->log->updated)->toBe(1)
            ->and(IndexNow::pendingCount())->toBe(0)
            ->and($product->fresh()->price)->toBe(15000000);
    });

    it('records a touch-only save', function () {
        enableIndexNow();
        $product = Product::factory()->create();
        Cache::forget(IndexNow::PENDING_KEY);

        $this->travel(1)->minutes();
        $product->touch();

        expect(IndexNow::pending()['product'])->toBe([$product->id]);
    });

    it('adds nothing for ignored columns', function (string $model, array $attributes) {
        enableIndexNow();
        $record = $model::factory()->create();
        Cache::forget(IndexNow::PENDING_KEY);

        $record->forceFill($attributes)->save();

        expect(IndexNow::pendingCount())->toBe(0);
    })->with([
        'product price' => [Product::class, ['price' => 999900]],
        'product dealer price' => [Product::class, ['price_dealer' => 888800]],
        'product wholesale price' => [Product::class, ['price_wholesale' => 777700]],
        'product sort' => [Product::class, ['sort' => 9]],
        'product featured' => [Product::class, ['is_featured' => true]],
        'product search text' => [Product::class, ['search_text' => 'x y z']],
        'product missing specs' => [Product::class, ['has_missing_specs' => true]],
        'category sort' => [Category::class, ['sort' => 9]],
        'brand sort' => [Brand::class, ['sort' => 9]],
        'page sort' => [Page::class, ['sort' => 9]],
        'page needs review' => [Page::class, ['needs_review' => true]],
    ]);
});

describe('inflight and backlog (IN-S13)', function () {
    it('queues at most one job while one is in flight', function () {
        enableIndexNow();
        Queue::fake();
        Artisan::call('indexnow:flush');

        Product::factory()->create(['slug' => 'first-batch']);
        Artisan::call('indexnow:flush');
        Product::factory()->create(['slug' => 'second-batch']);
        Artisan::call('indexnow:flush');

        Queue::assertPushed(SubmitIndexNow::class, 1);
        expect(implode("\n", IndexNow::backlog()))->toContain('second-batch');
    });

    it('carries URLs to the backlog and drops the oldest beyond the cap', function () {
        enableIndexNow();
        Queue::fake();
        Artisan::call('indexnow:flush');
        Cache::put(IndexNow::INFLIGHT_KEY, true, 600);
        Cache::forever(IndexNow::BACKLOG_KEY, array_map(fn (int $i): string => 'https://poson.example.uz/uz/old-'.$i, range(1, IndexNow::BACKLOG_CAP)));

        Product::factory()->create(['slug' => 'newest-item']);
        Artisan::call('indexnow:flush');

        Queue::assertNothingPushed();
        $backlog = IndexNow::backlog();
        expect($backlog)->toHaveCount(IndexNow::BACKLOG_CAP)
            ->and($backlog)->not->toContain('https://poson.example.uz/uz/old-1')
            ->and(implode("\n", array_slice($backlog, -5)))->toContain('newest-item');
    });
});

describe('dispatch failure (IN-S13)', function () {
    it('clears the flag, keeps the URLs and the old snapshot when dispatching throws', function () {
        enableIndexNow();
        Queue::fake();
        Artisan::call('indexnow:flush');
        $snapshot = Cache::get(IndexNow::SNAPSHOT_KEY);
        Product::factory()->create(['slug' => 'dispatch-fails']);

        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

        expect(fn () => Artisan::call('indexnow:flush'))->toThrow(RuntimeException::class, 'queue down');

        expect(Cache::has(IndexNow::INFLIGHT_KEY))->toBeFalse()
            ->and(implode("\n", IndexNow::backlog()))->toContain('dispatch-fails')
            ->and(Cache::get(IndexNow::SNAPSHOT_KEY))->toBe($snapshot);
    });
});

describe('submit job outcomes (IN-S13, IN-S15)', function () {
    it('releases on 429 with a clamped delay and keeps the flag', function (?string $retryAfter, int $delay) {
        enableIndexNow();
        Cache::put(IndexNow::INFLIGHT_KEY, true, 600);
        Http::fake(['api.indexnow.org/*' => Http::response('', 429, $retryAfter === null ? [] : ['Retry-After' => $retryAfter])]);
        $job = (new SubmitIndexNow(['https://poson.example.uz/uz']))->withFakeQueueInteractions();

        $job->handle();

        $job->assertReleased(delay: $delay);
        expect(Cache::has(IndexNow::INFLIGHT_KEY))->toBeTrue();
    })->with([
        'too small' => ['5', 60],
        'in range' => ['120', 120],
        'too large' => ['999999', 3600],
        'missing' => [null, 600],
    ]);

    it('fails without retry on 400, 403 and 422', function (int $status) {
        enableIndexNow();
        Http::fake(['api.indexnow.org/*' => Http::response('', $status)]);
        $job = (new SubmitIndexNow(['https://poson.example.uz/uz']))->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailed();
    })->with([400, 403, 422]);

    it('throws on 5xx so the queue retries, keeping the flag', function () {
        enableIndexNow();
        Cache::put(IndexNow::INFLIGHT_KEY, true, 600);
        Http::fake(['api.indexnow.org/*' => Http::response('', 503)]);

        expect(fn () => (new SubmitIndexNow(['https://poson.example.uz/uz']))->handle())->toThrow(RuntimeException::class)
            ->and(Cache::has(IndexNow::INFLIGHT_KEY))->toBeTrue();
    });

    it('clears the flag on 200 and 202', function (int $status) {
        enableIndexNow();
        Cache::put(IndexNow::INFLIGHT_KEY, true, 600);
        Http::fake(['api.indexnow.org/*' => Http::response('', $status)]);

        (new SubmitIndexNow(['https://poson.example.uz/uz']))->handle();

        expect(Cache::has(IndexNow::INFLIGHT_KEY))->toBeFalse();
    })->with([200, 202]);

    it('clears the flag in failed()', function () {
        Cache::put(IndexNow::INFLIGHT_KEY, true, 600);

        (new SubmitIndexNow([]))->failed(new RuntimeException('x'));

        expect(Cache::has(IndexNow::INFLIGHT_KEY))->toBeFalse();
    });

    it('clears the flag when the gate is off', function () {
        Cache::put(IndexNow::INFLIGHT_KEY, true, 600);

        (new SubmitIndexNow(['https://poson.example.uz/uz']))->handle();

        expect(Cache::has(IndexNow::INFLIGHT_KEY))->toBeFalse();
        Http::assertNothingSent();
    });
});

describe('key secrecy (IN-S4)', function () {
    it('keeps the key out of failed_jobs and the log when a job fails', function () {
        enableIndexNow();
        config([
            'queue.default' => 'database',
            'logging.default' => 'indexnow_capture',
            'logging.channels.indexnow_capture' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
        Log::forgetChannel('indexnow_capture');
        Http::fake(['api.indexnow.org/*' => Http::response('', 403)]);

        SubmitIndexNow::dispatch(['https://poson.example.uz/uz/page/about']);
        Artisan::call('queue:work', ['--once' => true, '--tries' => 1, '--stop-when-empty' => true]);

        $failed = DB::table('failed_jobs')->get();
        expect($failed)->toHaveCount(1)
            ->and(json_encode($failed))->not->toContain(IN_KEY);

        $records = Log::channel('indexnow_capture')->getLogger()->getHandlers()[0]->getRecords();
        expect($records)->not->toBeEmpty()
            ->and(json_encode(array_map(fn ($r): array => $r->toArray(), $records)))->not->toContain(IN_KEY)
            ->and(json_encode(IndexNow::entries()))->not->toContain(IN_KEY)
            ->and(Artisan::output())->not->toContain(IN_KEY);
    });
});

describe('key route (IN-S5, IN-S6, IN-S7)', function () {
    it('answers byte-identical 404s for disabled, mismatch and wrong length', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['app.url' => 'https://poson.example.uz']);
        $responses = [$this->get('https://poson.example.uz/'.IN_KEY.'.txt')];

        enableIndexNow();
        $responses[] = $this->get('https://poson.example.uz/'.strrev(IN_KEY).'.txt');
        $responses[] = $this->get('https://poson.example.uz/'.substr(IN_KEY, 0, 31).'.txt');
        $responses[] = $this->get('https://poson.example.uz/'.IN_KEY.'0.txt');

        foreach ($responses as $response) {
            $response->assertNotFound();
            expect($response->getContent())->toBe($responses[0]->getContent())
                ->and($response->headers->get('Cache-Control'))->toBe($responses[0]->headers->get('Cache-Control'))
                ->and($response->headers->get('Cache-Control'))->toContain('no-store')
                ->and($response->headers->getCookies())->toBe([]);
        }
    });

    it('serves 200 with nosniff, noindex and no cookie for a browser request', function () {
        enableIndexNow();

        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0', 'Accept' => 'text/html'])
            ->withUnencryptedCookie('foo', 'bar')
            ->get('https://poson.example.uz/'.IN_KEY.'.txt')
            ->assertOk();

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
            ->and($response->headers->get('Set-Cookie'))->toBeNull()
            ->and($response->headers->getCookies())->toBe([]);
    });

    it('throttles the 31st request even for the real key', function () {
        enableIndexNow();

        foreach (range(1, 30) as $ignored) {
            $this->get('https://poson.example.uz/'.IN_KEY.'.txt')->assertOk();
        }

        $this->get('https://poson.example.uz/'.IN_KEY.'.txt')->assertStatus(429);
    });
});
