<?php

use App\Enums\DeviceType;
use App\Enums\Role;
use App\Enums\TrafficChannel;
use App\Enums\VisitType;
use App\Models\BotHit;
use App\Models\Product;
use App\Models\SiteVisit;
use App\Support\Analytics\TrafficRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

const TRACK_HUMAN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const TRACK_IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const TRACK_GPTBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';

beforeEach(function () {
    config(['analytics.enabled' => true]);
    // The sitemap is a 404 while the site is closed for indexing (plan 15).
    openSearchIndexing();
});

/**
 * GET as a browser (or a crawler) from a given IP.
 *
 * @param  array<string, string>  $headers
 */
function trackedGet(string $uri, string $userAgent = TRACK_HUMAN_UA, array $headers = [], string $ip = '203.0.113.7'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(['User-Agent' => $userAgent, ...$headers])
        ->get($uri);
}

it('records a human entry with its channel, device, route and locale', function () {
    trackedGet('/ru', TRACK_IPHONE_UA, ['Referer' => 'https://www.google.co.uz/search?q=zebra'])->assertOk();

    $visit = SiteVisit::sole();

    expect($visit->type)->toBe(VisitType::PageView)
        ->and($visit->is_entry)->toBeTrue()
        ->and($visit->channel)->toBe(TrafficChannel::Search)
        ->and($visit->source)->toBe('google.co.uz')
        ->and($visit->referrer_host)->toBe('google.co.uz')
        ->and($visit->device)->toBe(DeviceType::Mobile)
        ->and($visit->route_name)->toBe('site.home')
        ->and($visit->path)->toBe('/ru')
        ->and($visit->locale)->toBe('ru')
        ->and($visit->status)->toBe(200)
        ->and($visit->visitor_hash)->toMatch('/^[0-9a-f]{16}$/');

    expect(BotHit::count())->toBe(0);
});

it('stores the first-touch attribution and records later pages under it, not as entries', function () {
    trackedGet('/uz?utm_source=chatgpt.com&utm_medium=referral', TRACK_HUMAN_UA)->assertOk();

    expect(session('attribution'))->toMatchArray([
        'channel' => 'ai',
        'source' => 'chatgpt.com',
        'utm_source' => 'chatgpt.com',
        'utm_medium' => 'referral',
        'landing' => '/uz',
    ]);

    // Internal navigation, then a new external referrer in the same session.
    trackedGet('/uz/catalog', TRACK_HUMAN_UA, ['Referer' => config('app.url').'/uz'])->assertOk();
    trackedGet('/uz/search', TRACK_HUMAN_UA, ['Referer' => 'https://t.me/poson'])->assertOk();

    $visits = SiteVisit::query()->orderBy('id')->get();

    expect($visits)->toHaveCount(3)
        ->and($visits->pluck('is_entry')->all())->toBe([true, false, false])
        ->and($visits->pluck('channel')->unique()->all())->toBe([TrafficChannel::Ai])
        ->and($visits->pluck('utm_medium')->unique()->all())->toBe(['referral'])
        ->and(session('attribution.channel'))->toBe('ai')
        ->and($visits->pluck('visitor_hash')->unique())->toHaveCount(1);
});

it('records the product of a product page', function () {
    $product = Product::factory()->create();

    trackedGet("/en/product/{$product->slug}")->assertOk();

    expect(SiteVisit::sole())
        ->route_name->toBe('site.product')
        ->product_id->toBe($product->id)
        ->locale->toBe('en');
});

it('records 404s for unknown URLs, including the locale', function () {
    trackedGet('/uz/no-such-page', TRACK_HUMAN_UA, ['Referer' => 'https://perplexity.ai/search'])->assertNotFound();

    expect(SiteVisit::sole())
        ->status->toBe(404)
        ->route_name->toBeNull()
        ->path->toBe('/uz/no-such-page')
        ->locale->toBe('uz')
        ->channel->toBe(TrafficChannel::Ai)
        ->is_entry->toBeTrue();
});

it('records crawlers fetching robots.txt and the sitemap, but not people opening them', function () {
    trackedGet('/robots.txt')->assertOk();
    trackedGet('/sitemap.xml')->assertOk();
    trackedGet('/robots.txt', TRACK_GPTBOT_UA)->assertOk();
    trackedGet('/sitemap.xml', TRACK_GPTBOT_UA)->assertOk();

    expect(SiteVisit::count())->toBe(0)
        ->and(BotHit::query()->orderBy('id')->pluck('route_name')->all())->toBe(['robots', 'sitemap']);
});

it('writes crawlers to bot_hits only, with their packed IP and no session', function () {
    config(['session.driver' => 'database']);

    $response = trackedGet('/uz', TRACK_GPTBOT_UA, [], '20.171.207.10')->assertOk();

    $hit = BotHit::sole();

    expect($hit->bot)->toBe('gptbot')
        ->and($hit->ipAddress())->toBe('20.171.207.10')
        ->and(strlen($hit->ip))->toBe(4)
        ->and($hit->verified)->toBeNull()
        ->and($hit->route_name)->toBe('site.home')
        ->and($hit->status)->toBe(200)
        ->and(SiteVisit::count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(0)
        ->and(config('session.driver'))->toBe('database');

    // No cookies at all for crawlers: no session, XSRF or locale cookie.
    expect($response->headers->getCookies())->toBe([]);

    // A person on the same page does get a session row.
    trackedGet('/uz')->assertOk();

    expect(DB::table('sessions')->count())->toBe(1)
        ->and(SiteVisit::count())->toBe(1);
});

it('records crawler 404s', function () {
    trackedGet('/en/old-page.html', 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)')->assertNotFound();

    expect(BotHit::sole())->bot->toBe('claudebot')->status->toBe(404)->path->toBe('/en/old-page.html');
});

it('does not record signed-in staff', function (Role $role) {
    $this->actingAs(staffUser($role));

    trackedGet('/uz')->assertOk();
    trackedGet('/uz/catalog')->assertOk();

    expect(SiteVisit::count())->toBe(0);
})->with([
    'admin' => [Role::Admin],
    'content manager' => [Role::ContentManager],
]);

it('does not record prefetches, HEAD requests or the admin panel', function () {
    trackedGet('/uz', TRACK_HUMAN_UA, ['Sec-Purpose' => 'prefetch'])->assertOk();
    trackedGet('/uz', TRACK_HUMAN_UA, ['Purpose' => 'prefetch'])->assertOk();
    trackedGet('/uz', TRACK_HUMAN_UA, ['Sec-Purpose' => 'prefetch;prerender'])->assertOk();
    $this->withHeaders(['User-Agent' => TRACK_HUMAN_UA])->head('/uz')->assertOk();
    trackedGet('/admin/login')->assertOk();
    trackedGet('/admin/login', TRACK_GPTBOT_UA)->assertOk();

    expect(SiteVisit::count())->toBe(0)
        ->and(BotHit::count())->toBe(0);
});

it('records nothing when tracking is disabled', function () {
    config(['analytics.enabled' => false]);

    trackedGet('/uz')->assertOk();
    trackedGet('/uz', TRACK_GPTBOT_UA)->assertOk();

    expect(SiteVisit::count() + BotHit::count())->toBe(0);
});

it('stores no raw IP for people, only a daily-salted hash', function () {
    $ip = '198.51.100.23';

    trackedGet('/uz', TRACK_HUMAN_UA, [], $ip)->assertOk();
    $first = SiteVisit::sole();

    $row = (array) DB::table('site_visits')->first();
    $packed = inet_pton($ip);

    foreach ($row as $value) {
        expect((string) $value)->not->toContain($ip)
            ->and((string) $value)->not->toContain($packed);
    }

    // Same visitor the same day → same hash; the next day the salt changes.
    trackedGet('/uz/catalog', TRACK_HUMAN_UA, [], $ip)->assertOk();
    $this->travel(1)->days();
    trackedGet('/uz/catalog', TRACK_HUMAN_UA, [], $ip)->assertOk();

    $hashes = SiteVisit::query()->orderBy('id')->pluck('visitor_hash')->all();

    expect($hashes[1])->toBe($first->visitor_hash)
        ->and($hashes[2])->not->toBe($first->visitor_hash);
});

it('stops inserting past the daily cap and reports once per kind', function () {
    config(['analytics.max_raw_per_day.visits' => 2]);
    Log::spy();

    foreach (range(1, 4) as $i) {
        trackedGet('/uz')->assertOk();
    }

    expect(SiteVisit::count())->toBe(2);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'raw row cap') && $context['kind'] === 'visits');
});

it('keeps recording people when a crawler flood hits the bot cap', function () {
    config(['analytics.max_raw_per_day.bots' => 2]);

    foreach (range(1, 6) as $i) {
        trackedGet('/uz', TRACK_GPTBOT_UA)->assertOk();
    }

    trackedGet('/uz')->assertOk();
    trackedGet('/uz/catalog')->assertOk();

    expect(BotHit::count())->toBe(2)
        ->and(SiteVisit::count())->toBe(2);
});

it('stores crawler IPs only for crawlers that can be verified', function () {
    trackedGet('/uz', 'Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)', [], '198.51.100.9')->assertOk();
    trackedGet('/uz', 'SomeCrawler/1.0', [], '198.51.100.10')->assertOk();
    trackedGet('/uz', TRACK_GPTBOT_UA, [], '198.51.100.11')->assertOk();

    expect(BotHit::query()->orderBy('id')->get()->map(fn (BotHit $hit): ?string => $hit->ipAddress())->all())
        ->toBe([null, null, '198.51.100.11']);
});

it('packs IPv6 and unwraps IPv4-mapped addresses', function () {
    trackedGet('/uz', TRACK_GPTBOT_UA, [], '::ffff:20.171.206.15')->assertOk();
    trackedGet('/uz', TRACK_GPTBOT_UA, [], '2a01:111:f400::1')->assertOk();

    [$mapped, $v6] = BotHit::query()->orderBy('id')->get()->all();

    expect(strlen($mapped->ip))->toBe(4)
        ->and($mapped->ipAddress())->toBe('20.171.206.15')
        ->and(strlen($v6->ip))->toBe(16)
        ->and($v6->ipAddress())->toBe('2a01:111:f400::1');
});

it('never breaks a page when recording fails', function () {
    $this->mock(TrafficRecorder::class, function ($mock): void {
        $mock->shouldReceive('recordPageView')->andThrow(new RuntimeException('database down'));
        $mock->shouldReceive('recordBotHit')->andThrow(new RuntimeException('database down'));
    });
    Exceptions::fake();

    trackedGet('/uz')->assertOk();
    trackedGet('/uz', TRACK_GPTBOT_UA)->assertOk();

    // Reported, but at most once a minute.
    Exceptions::assertReportedCount(1);
});

it('never breaks a page when the salt cache store fails', function () {
    config(['analytics.salt_store' => 'missing-store']);

    trackedGet('/uz')->assertOk();

    expect(SiteVisit::count())->toBe(0);
});

it('reports a failing insert at most once a minute', function () {
    Exceptions::fake();
    Schema::drop('site_visits');

    trackedGet('/uz')->assertOk();
    trackedGet('/uz/catalog')->assertOk();

    Exceptions::assertReportedCount(1);

    $this->travel(61)->seconds();
    trackedGet('/uz/search')->assertOk();

    Exceptions::assertReportedCount(2);
});

it('does not record webhooks, the API, Livewire updates or Livewire assets', function () {
    trackedGet('/webhooks/payme')->assertNotFound();
    trackedGet('/webhooks/click', TRACK_GPTBOT_UA)->assertNotFound();
    trackedGet('/api/anything')->assertNotFound();
    trackedGet(str_replace('/update', '/livewire.js', route('default-livewire.update', absolute: false)));
    $this->withHeaders(['User-Agent' => TRACK_HUMAN_UA])->post(route('default-livewire.update'), []);

    expect(SiteVisit::count())->toBe(0)
        ->and(BotHit::count())->toBe(0);
});

it('cuts long values and drops control characters and invalid UTF-8 from UTM tags, referrers and paths', function () {
    $query = http_build_query([
        'utm_source' => str_repeat('s', 400),
        'utm_medium' => "cpc\x00\x07",
        'utm_campaign' => "\xFF\xFEautumn",
    ]);

    trackedGet('/uz?'.$query, TRACK_HUMAN_UA, ['Referer' => "https://exa\x01mple.com/"])->assertOk();
    trackedGet('/uz/no-page-'.str_repeat('x', 300), TRACK_HUMAN_UA, ['Referer' => 'https://'.str_repeat('a', 300).'.com/'])->assertNotFound();
    // Malformed UTF-8 in the path is refused by Laravel (400) before tracking runs.
    trackedGet('/uz/%FF%FEbad')->assertStatus(400);

    [$entry, $notFound] = SiteVisit::query()->orderBy('id')->get()->all();

    expect(SiteVisit::count())->toBe(2)
        ->and(mb_strlen($entry->source))->toBe(100)
        ->and($entry->utm_medium)->toBe('cpc')
        ->and($entry->utm_campaign)->toBeNull()
        ->and($entry->referrer_host)->toBeNull()
        ->and(mb_strlen(session('attribution.utm_source')))->toBe(255)
        ->and(mb_strlen($notFound->path))->toBe(255)
        ->and(mb_strlen((string) $notFound->referrer_host))->toBeLessThanOrEqual(150);
});

it('counts own-host subdomains as internal navigation', function () {
    $host = parse_url(config('app.url'), PHP_URL_HOST);

    trackedGet('/uz', TRACK_HUMAN_UA, ['Referer' => "https://shop.{$host}/"])->assertOk();

    expect(SiteVisit::sole())->is_entry->toBeFalse()->channel->toBe(TrafficChannel::Direct);
});

it('warns outside local when the visitor salt sits in a persistent cache store', function () {
    $this->app['env'] = 'production';
    config(['analytics.salt_store' => 'database']);
    Log::spy();

    trackedGet('/uz')->assertOk();
    trackedGet('/uz/catalog')->assertOk();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'visitor salt'));
    expect(SiteVisit::count())->toBe(2);
});
