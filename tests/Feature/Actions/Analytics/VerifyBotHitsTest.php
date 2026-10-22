<?php

use App\Actions\Analytics\AggregateTraffic;
use App\Actions\Analytics\RefreshBotIpRanges;
use App\Actions\Analytics\VerifyBotHits;
use App\Models\BotHit;
use App\Models\TrafficDaily;
use App\Support\Analytics\BotIpRanges;
use App\Support\Analytics\BotRegistry;
use App\Support\Analytics\DnsResolver;
use App\Support\Analytics\IpAddress;
use App\Support\Analytics\TrafficRecorder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * DNS answers for the verifier; counts every lookup.
 */
class FakeDnsResolver implements DnsResolver
{
    public int $reverseLookups = 0;

    /**
     * @param  array<string, string>  $ptr  ip => host
     * @param  array<string, list<string>>  $hosts  host => ips
     */
    public function __construct(public array $ptr = [], public array $hosts = []) {}

    public function reverse(string $ip): ?string
    {
        $this->reverseLookups++;

        return $this->ptr[$ip] ?? null;
    }

    public function forward(string $host): array
    {
        return $this->hosts[$host] ?? [];
    }
}

beforeEach(function () {
    $this->rangesPath = storage_path('framework/testing/bot-ranges-'.uniqid().'.json');
    config(['analytics.verification.ranges_path' => $this->rangesPath]);

    $this->dns = new FakeDnsResolver(
        ptr: [
            '66.249.66.1' => 'crawl-66-249-66-1.googlebot.com',
            '5.255.253.1' => '5-255-253-1.spider.yandex.com',
            '6.6.6.6' => 'crawl-6-6-6-6.googlebot.com.evil.example',
            '7.7.7.7' => 'fake.googlebot.com',
        ],
        hosts: [
            'crawl-66-249-66-1.googlebot.com' => ['66.249.66.1'],
            '5-255-253-1.spider.yandex.com' => ['5.255.253.1'],
            'fake.googlebot.com' => ['8.8.8.8'],
        ],
    );
    $this->app->instance(DnsResolver::class, $this->dns);

    $this->yesterday = now(TrafficRecorder::timezone())->subDay()->setTime(12, 0)->utc();
});

afterEach(function () {
    File::delete($this->rangesPath);
});

function hit(string $bot, string $ip, mixed $at = null): BotHit
{
    return BotHit::factory()->fromIp($ip)->create(['bot' => $bot, 'occurred_at' => $at ?? test()->yesterday]);
}

it('verifies by published IP ranges and flags a spoofed User-Agent', function () {
    app(BotIpRanges::class)->save(['gptbot' => ['20.171.206.0/24', '2a01:111::/32']]);

    $genuine = hit('gptbot', '20.171.206.15');
    $genuineV6 = hit('gptbot', '2a01:111:f400::1');
    $spoofed = hit('gptbot', '203.0.113.50');
    hit('gptbot', '20.171.206.15'); // same IP twice: both rows updated

    $stats = app(VerifyBotHits::class)->handle($this->yesterday->setTimezone(TrafficRecorder::timezone())->toDateString());

    expect($stats)->toMatchArray(['checked' => 3, 'verified' => 2, 'spoofed' => 1, 'undecided' => 0])
        ->and($genuine->fresh()->verified)->toBeTrue()
        ->and($genuineV6->fresh()->verified)->toBeTrue()
        ->and($spoofed->fresh()->verified)->toBeFalse()
        ->and(BotHit::where('verified', true)->count())->toBe(3)
        ->and($this->dns->reverseLookups)->toBe(0);
});

it('verifies by forward-confirmed reverse DNS', function () {
    $google = hit('googlebot', '66.249.66.1');
    $yandex = hit('yandexbot', '5.255.253.1');
    $wrongDomain = hit('googlebot', '6.6.6.6');
    $noForwardMatch = hit('googlebot', '7.7.7.7');
    $noPtr = hit('yandexbot', '9.9.9.9');

    app(VerifyBotHits::class)->handle($this->yesterday->setTimezone(TrafficRecorder::timezone())->toDateString());

    expect($google->fresh()->verified)->toBeTrue()
        ->and($yandex->fresh()->verified)->toBeTrue()
        ->and($wrongDomain->fresh()->verified)->toBeFalse()
        ->and($noForwardMatch->fresh()->verified)->toBeFalse()
        ->and($noPtr->fresh()->verified)->toBeFalse();
});

it('falls back to reverse DNS when an IP is outside the published ranges of a bot that has rDNS domains', function () {
    app(BotIpRanges::class)->save(['googlebot' => ['192.0.2.0/24']]);

    $hit = hit('googlebot', '66.249.66.1');

    app(VerifyBotHits::class)->handle($this->yesterday->setTimezone(TrafficRecorder::timezone())->toDateString());

    expect($hit->fresh()->verified)->toBeTrue()
        ->and($this->dns->reverseLookups)->toBe(1);
});

it('leaves User-Agent-only bots and bots without loaded ranges unverified', function () {
    $bytespider = hit('bytespider', '1.2.3.4');
    $other = hit('other', '1.2.3.5');
    $claude = hit('claudebot', '1.2.3.6'); // ranges not downloaded yet

    $stats = app(VerifyBotHits::class)->handle($this->yesterday->setTimezone(TrafficRecorder::timezone())->toDateString());

    expect($bytespider->fresh()->verified)->toBeNull()
        ->and($other->fresh()->verified)->toBeNull()
        ->and($claude->fresh()->verified)->toBeNull()
        ->and($stats['undecided'])->toBe(1);
});

it('caches verdicts per IP and respects the daily DNS budget (reverse and forward each count)', function () {
    config(['analytics.verification.max_lookups_per_day' => 2]);

    hit('googlebot', '66.249.66.1');
    $second = hit('yandexbot', '5.255.253.1');

    $date = $this->yesterday->setTimezone(TrafficRecorder::timezone())->toDateString();
    $stats = app(VerifyBotHits::class)->handle($date);

    expect($stats['undecided'])->toBe(1)
        ->and($second->fresh()->verified)->toBeNull()
        ->and($this->dns->reverseLookups)->toBe(1);

    // The same IP another day: answered from the cache, no lookup.
    $again = hit('googlebot', '66.249.66.1', now(TrafficRecorder::timezone())->setTime(9, 0)->utc());
    app(VerifyBotHits::class)->handle(now(TrafficRecorder::timezone())->toDateString());

    expect($again->fresh()->verified)->toBeTrue()
        ->and($this->dns->reverseLookups)->toBe(1);
});

it('downloads the published ranges and keeps the old ones when a list fails', function () {
    app(BotIpRanges::class)->save(['claudebot' => ['216.73.216.0/22']]);

    Http::fake([
        'openai.com/gptbot.json' => Http::response(['creationTime' => 'x', 'prefixes' => [['ipv4Prefix' => '20.171.206.0/24'], ['ipv6Prefix' => '2a01:111::/32'], ['ipv4Prefix' => 'not-a-cidr']]]),
        'claude.com/*' => Http::response('Service unavailable', 503),
        '*' => Http::response(['prefixes' => [['ipv4Prefix' => '192.0.2.0/24']]]),
    ]);

    $result = app(RefreshBotIpRanges::class)->handle();
    $stored = json_decode(File::get($this->rangesPath), true);

    expect($stored['bots']['gptbot'])->toBe(['20.171.206.0/24', '2a01:111::/32'])
        ->and($stored['bots']['claudebot'])->toBe(['216.73.216.0/22'])
        ->and($stored['bots'])->not->toHaveKey('claude-user')
        ->and($stored['bots'])->not->toHaveKey('yandexbot')
        ->and($result['failed'])->toContain('claudebot', 'claude-searchbot', 'claude-user')
        ->and($result['updated'])->toHaveKey('gptbot');

    // The Anthropic list is shared by three bots: fetched once (two attempts as it fails).
    Http::assertSentCount(count(array_unique(array_merge(...array_map(
        fn ($bot) => $bot->ipRangeUrls,
        array_values(BotRegistry::trackable()),
    )))) + 1);
});

it('verifies IPv6 and IPv4-mapped addresses', function () {
    app(BotIpRanges::class)->save(['gptbot' => ['20.171.206.0/24']]);
    $this->dns->ptr['2001:4860:4801:10::1'] = 'crawl-2001-4860-4801-10--1.googlebot.com';
    $this->dns->hosts['crawl-2001-4860-4801-10--1.googlebot.com'] = ['2001:4860:4801:10:0:0:0:1'];

    $mapped = hit('gptbot', '::ffff:20.171.206.15');
    $v6 = hit('googlebot', '2001:4860:4801:10::1');

    app(VerifyBotHits::class)->handle($this->yesterday->setTimezone(TrafficRecorder::timezone())->toDateString());

    expect($mapped->fresh()->verified)->toBeTrue()
        ->and($mapped->fresh()->ipAddress())->toBe('20.171.206.15')
        ->and($v6->fresh()->verified)->toBeTrue();

    expect(IpAddress::normalize('::ffff:1.2.3.4'))->toBe('1.2.3.4')
        ->and(IpAddress::normalize('::FFFF:1.2.3.4'))->toBe('1.2.3.4')
        ->and(IpAddress::normalize('2001:DB8::1'))->toBe('2001:db8::1')
        ->and(IpAddress::normalize('not-an-ip'))->toBeNull()
        ->and(strlen(IpAddress::pack('::ffff:1.2.3.4')))->toBe(4)
        ->and(app(BotIpRanges::class)->contains('gptbot', '::ffff:20.171.206.200'))->toBeTrue();
});

it('ignores published prefixes broader than /8 (IPv4) or /16 (IPv6)', function (string $cidr, bool $valid) {
    expect(BotIpRanges::isCidr($cidr))->toBe($valid);
})->with([
    ['0.0.0.0/0', false],
    ['10.0.0.0/7', false],
    ['10.0.0.0/8', true],
    ['20.171.206.0/24', true],
    ['1.2.3.4', true],
    ['2001::/15', false],
    ['2001:db8::/16', true],
    ['2001:db8::/129', false],
    ['bogus/24', false],
]);

it('downloads range lists over https only and refuses oversized bodies', function () {
    config([
        'analytics.verification.max_body_bytes' => 200,
        'ai-bots.gptbot.ip_ranges' => ['http://openai.com/gptbot.json'],
    ]);
    BotRegistry::flush();

    Http::fake([
        'www.bing.com/*' => Http::response(['prefixes' => array_fill(0, 20, ['ipv4Prefix' => '157.55.39.0/24'])]),
        '*' => Http::response(['prefixes' => [['ipv4Prefix' => '192.0.2.0/24']]]),
    ]);

    $result = app(RefreshBotIpRanges::class)->handle();

    expect($result['failed'])->toContain('gptbot', 'bingbot')
        ->and($result['updated'])->toHaveKey('applebot');

    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'http://'));

    BotRegistry::flush();
});

it('verifies the last three days nightly, downloading stale ranges first, and re-aggregates them', function () {
    Http::fake(['*' => Http::response(['prefixes' => [['ipv4Prefix' => '20.171.206.0/24']]])]);

    $threeDaysAgo = now(TrafficRecorder::timezone())->subDays(3)->setTime(12, 0);
    $date = $threeDaysAgo->toDateString();
    hit('gptbot', '20.171.206.15', $threeDaysAgo->utc());
    hit('gptbot', '20.171.206.16', now(TrafficRecorder::timezone())->subDays(4)->setTime(12, 0)->utc()); // outside the window

    app(AggregateTraffic::class)->handle($date);
    expect(TrafficDaily::query()->where('date', $date)->where('value', 'gptbot')->sole()->verified_hits)->toBe(0);

    expect(File::exists($this->rangesPath))->toBeFalse();

    $this->artisan('analytics:verify-bots')->assertSuccessful()->expectsOutputToContain('Downloaded');

    expect(File::exists($this->rangesPath))->toBeTrue()
        ->and(TrafficDaily::query()->where('date', $date)->where('value', 'gptbot')->sole()->verified_hits)->toBe(1)
        ->and(BotHit::query()->whereNull('verified')->count())->toBe(1);

    // Fresh ranges: not downloaded again.
    Http::fake();
    $this->artisan('analytics:verify-bots')->assertSuccessful();
    Http::assertNothingSent();
});

it('verifies and re-aggregates one given older day from artisan', function () {
    $older = now(TrafficRecorder::timezone())->subDays(6)->setTime(12, 0);
    app(BotIpRanges::class)->save(['gptbot' => ['20.171.206.0/24']]);
    hit('gptbot', '20.171.206.15', $older->utc());

    $this->artisan('analytics:verify-bots', ['date' => $older->toDateString()])->assertSuccessful();

    expect(TrafficDaily::query()->where('date', $older->toDateString())->where('value', 'gptbot')->sole()->verified_hits)->toBe(1);

    $this->artisan('analytics:verify-bots', ['date' => 'last week'])->assertFailed();
});
