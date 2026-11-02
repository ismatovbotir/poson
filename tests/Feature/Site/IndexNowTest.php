<?php

use App\Actions\Settings\GenerateIndexNowKey;
use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Jobs\SubmitIndexNow;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Secrets;
use App\Support\Site\IndexNow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const INDEXNOW_TEST_KEY = 'abcdef0123456789abcdef0123456789';

/**
 * Turn IndexNow fully on: production, public https APP_URL, indexing open, key, switch.
 */
function activateIndexNow(): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.url' => 'https://poson.example.uz']);
    openSearchIndexing();
    app(SetSecret::class)->handle('indexnow.key', INDEXNOW_TEST_KEY, null, null);
    app(SaveIntegrationSettings::class)->handle(['indexnow.enabled' => true], null, null);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

// Production requests set Symfony's static trusted hosts; never leak them into other tests.
afterEach(function () {
    Request::setTrustedHosts([]);
});

it('is active when every condition holds', function () {
    activateIndexNow();

    expect(IndexNow::isActive())->toBeTrue();
});

it('reports inactive for each failing gate condition', function () {
    activateIndexNow();
    openSearchIndexing(false);
    expect(IndexNow::isActive())->toBeFalse();

    openSearchIndexing();
    config(['app.url' => 'http://localhost']);
    expect(IndexNow::isActive())->toBeFalse();

    config(['app.url' => 'https://poson.example.uz']);
    app()->detectEnvironment(fn (): string => 'local');
    expect(IndexNow::isActive())->toBeFalse();

    app()->detectEnvironment(fn (): string => 'production');
    app(SaveIntegrationSettings::class)->handle(['indexnow.enabled' => false], null, null);
    expect(IndexNow::isActive())->toBeFalse();
});

it('generates a valid key without returning it', function () {
    app(GenerateIndexNowKey::class)->handle(null, null);

    expect(Secrets::get('indexnow.key'))->toMatch('/^[a-f0-9]{32}$/');
});

describe('key route (IN-S5, IN-S6, IN-S7)', function () {
    it('serves the exact key as text without cookies while active', function () {
        activateIndexNow();

        $response = $this->get('https://poson.example.uz/'.INDEXNOW_TEST_KEY.'.txt')->assertOk();

        expect($response->getContent())->toBe(INDEXNOW_TEST_KEY)
            ->and($response->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
            ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->getCookies())->toBe([]);
    });

    it('answers the same 404 when disabled, mismatched or of a wrong length', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['app.url' => 'https://poson.example.uz']);
        $disabled = $this->get('https://poson.example.uz/'.INDEXNOW_TEST_KEY.'.txt')->assertNotFound();

        activateIndexNow();

        $wrong = $this->get('https://poson.example.uz/'.strrev(INDEXNOW_TEST_KEY).'.txt')->assertNotFound();
        $short = $this->get('https://poson.example.uz/abc.txt')->assertNotFound();

        expect($wrong->getContent())->toBe($disabled->getContent())
            ->and($short->getContent())->toBe($disabled->getContent())
            ->and($disabled->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('is throttled to 30 per minute per IP', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['app.url' => 'https://poson.example.uz']);

        foreach (range(1, 30) as $ignored) {
            $this->get('https://poson.example.uz/'.INDEXNOW_TEST_KEY.'.txt')->assertNotFound();
        }

        $this->get('https://poson.example.uz/'.INDEXNOW_TEST_KEY.'.txt')->assertStatus(429);
    });
});

describe('recorder (IN-S2, IN-S12, IN-S16)', function () {
    it('records nothing while the gate is off', function () {
        Product::factory()->create();

        expect(IndexNow::pendingCount())->toBe(0);
    });

    it('records content changes but not price-only changes', function () {
        activateIndexNow();
        $product = Product::factory()->create();
        Cache::forget(IndexNow::PENDING_KEY);

        $product->update(['price' => 123400]);
        expect(IndexNow::pendingCount())->toBe(0);

        $product->update(['stock_status' => 'out_of_stock']);
        expect(IndexNow::pending()['product'])->toBe([$product->id]);
    });

    it('records nothing after a rollback', function () {
        activateIndexNow();
        Cache::forget(IndexNow::PENDING_KEY);
        $product = Product::factory()->create();
        Cache::forget(IndexNow::PENDING_KEY);

        DB::beginTransaction();
        $product->update(['slug' => 'changed-slug']);
        DB::rollBack();

        expect(IndexNow::pendingCount())->toBe(0);
    });

    it('survives a held lock without throwing', function () {
        activateIndexNow();
        $product = Product::factory()->create();
        Cache::forget(IndexNow::PENDING_KEY);

        $lock = Cache::lock(IndexNow::LOCK_KEY, 30);
        $lock->get();

        $product->update(['slug' => 'locked-slug']);

        expect(IndexNow::pendingCount())->toBe(0);
        $lock->release();
    });

    it('caps pending ids per type', function () {
        activateIndexNow();
        Cache::forever(IndexNow::PENDING_KEY, ['product' => range(1, IndexNow::PENDING_CAP)]);

        IndexNow::append('product', 999999);

        expect(IndexNow::pending()['product'])->toHaveCount(IndexNow::PENDING_CAP);
    });
});

describe('flush command (IN-S3, IN-S11, IN-S13)', function () {
    it('only seeds the snapshot on the first run', function () {
        activateIndexNow();
        Queue::fake();
        Product::factory()->count(2)->create();

        Artisan::call('indexnow:flush');

        Queue::assertNothingPushed();
        expect(Cache::get(IndexNow::SNAPSHOT_KEY))->toBeArray()->not->toBeEmpty();
    });

    it('queues added and removed URLs and carries the rest in the backlog', function () {
        activateIndexNow();
        Queue::fake();
        $kept = Product::factory()->create();
        Artisan::call('indexnow:flush');

        $added = Product::factory()->create();
        $kept->update(['is_active' => false]);
        Artisan::call('indexnow:flush');

        Queue::assertPushed(SubmitIndexNow::class, function (SubmitIndexNow $job) use ($added, $kept): bool {
            $joined = implode("\n", $job->urls);

            return str_contains($joined, '/product/'.$added->slug)
                && str_contains($joined, '/product/'.$kept->slug)
                && count($job->urls) === count(array_unique($job->urls));
        });
    });

    it('splits more than 10,000 URLs into one batch plus backlog', function () {
        activateIndexNow();
        Queue::fake();
        Cache::forever(IndexNow::SNAPSHOT_KEY, array_map(fn (int $i): string => 'https://poson.example.uz/uz/gone-'.$i, range(1, 25000)));

        Artisan::call('indexnow:flush');

        Queue::assertPushed(SubmitIndexNow::class, fn (SubmitIndexNow $job): bool => count($job->urls) === IndexNow::MAX_URLS);
        expect(IndexNow::backlog())->toHaveCount(15006);
    });

    it('forgets all state while the gate is off', function () {
        Cache::forever(IndexNow::SNAPSHOT_KEY, ['x']);
        Cache::forever(IndexNow::BACKLOG_KEY, ['x']);

        Artisan::call('indexnow:flush');

        expect(Cache::get(IndexNow::SNAPSHOT_KEY))->toBeNull()
            ->and(Cache::get(IndexNow::BACKLOG_KEY))->toBeNull();
    });
});

describe('submit job (IN-S4, IN-S14, IN-S15)', function () {
    it('posts the documented payload on APP_URL and logs no key or URLs', function () {
        activateIndexNow();
        Http::fake(['api.indexnow.org/*' => Http::response('', 200)]);
        $urls = ['https://poson.example.uz/uz/page/about'];

        (new SubmitIndexNow($urls))->handle();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.indexnow.org/indexnow'
            && $request['host'] === 'poson.example.uz'
            && $request['key'] === INDEXNOW_TEST_KEY
            && $request['keyLocation'] === 'https://poson.example.uz/'.INDEXNOW_TEST_KEY.'.txt'
            && $request['urlList'] === $urls);

        expect(json_encode(IndexNow::entries()))->not->toContain(INDEXNOW_TEST_KEY)->not->toContain('page/about')
            ->and(serialize(new SubmitIndexNow($urls)))->not->toContain(INDEXNOW_TEST_KEY);
    });

    it('skips quietly without HTTP while the gate is off', function () {
        (new SubmitIndexNow(['https://poson.example.uz/uz']))->handle();

        Http::assertNothingSent();
    });

    it('releases on 429, fails on 403 and throws on 500', function (int $status) {
        activateIndexNow();
        Http::fake(['api.indexnow.org/*' => Http::response('', $status, ['Retry-After' => '5'])]);
        $job = new SubmitIndexNow(['https://poson.example.uz/uz']);

        if ($status === 500) {
            expect(fn () => $job->handle())->toThrow(RuntimeException::class);
        } else {
            $job->handle();
        }

        expect(IndexNow::entries()[0]['code'])->toBe((string) $status);
    })->with([429, 403, 500]);
});

it('keeps the model hooks recording categories and brands', function () {
    activateIndexNow();
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();

    expect(IndexNow::pending()['category'])->toContain($category->id)
        ->and(IndexNow::pending()['brand'])->toContain($brand->id);
});
