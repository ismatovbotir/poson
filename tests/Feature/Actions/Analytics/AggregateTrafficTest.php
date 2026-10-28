<?php

use App\Actions\Analytics\AggregateTraffic;
use App\Actions\Analytics\PruneAnalytics;
use App\Enums\DeviceType;
use App\Enums\TrafficChannel;
use App\Enums\VisitType;
use App\Models\BotHit;
use App\Models\SiteVisit;
use App\Models\TrafficDaily;
use App\Models\TrafficDailyPage;
use App\Support\Analytics\TrafficRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tz = TrafficRecorder::timezone();
    $this->date = '2026-10-20';
    // Noon in Tashkent on that date, stored in UTC like the recorder does.
    $this->at = CarbonImmutable::parse($this->date.' 12:00', $this->tz)->utc();
});

/**
 * One day: two visitors from ChatGPT (one lead), one from Google on a phone,
 * one direct with a 404; three crawler hits (one verified, one spoofed).
 */
function seedRawDay(CarbonImmutable $at): void
{
    SiteVisit::factory()->entry(TrafficChannel::Ai, 'chatgpt.com')->create(['occurred_at' => $at, 'visitor_hash' => 'aaaaaaaaaaaaaaaa', 'path' => '/uz/product/zd421', 'product_id' => 7]);
    SiteVisit::factory()->create(['occurred_at' => $at, 'visitor_hash' => 'aaaaaaaaaaaaaaaa', 'channel' => TrafficChannel::Ai, 'source' => 'chatgpt.com', 'path' => '/uz/catalog']);
    SiteVisit::factory()->lead(TrafficChannel::Ai, 'chatgpt.com')->create(['occurred_at' => $at, 'visitor_hash' => 'aaaaaaaaaaaaaaaa', 'path' => '/uz/product/zd421']);
    SiteVisit::factory()->entry(TrafficChannel::Ai, 'chatgpt.com')->create(['occurred_at' => $at, 'visitor_hash' => 'bbbbbbbbbbbbbbbb', 'path' => '/uz/product/zd421', 'product_id' => 7, 'locale' => 'ru']);
    SiteVisit::factory()->entry(TrafficChannel::Search, 'google.com')->create(['occurred_at' => $at, 'visitor_hash' => 'cccccccccccccccc', 'device' => DeviceType::Mobile, 'path' => '/uz']);
    SiteVisit::factory()->entry()->create(['occurred_at' => $at, 'visitor_hash' => 'dddddddddddddddd', 'path' => '/uz/old', 'status' => 404, 'route_name' => null]);

    BotHit::factory()->fromIp('20.171.206.1')->create(['occurred_at' => $at, 'bot' => 'gptbot', 'verified' => true, 'path' => '/uz/product/zd421', 'product_id' => 7]);
    BotHit::factory()->fromIp('203.0.113.9')->create(['occurred_at' => $at, 'bot' => 'gptbot', 'verified' => false, 'path' => '/uz']);
    BotHit::factory()->fromIp('66.249.66.1')->create(['occurred_at' => $at, 'bot' => 'googlebot', 'path' => '/en/missing', 'status' => 404]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function dailySnapshot(): array
{
    return TrafficDaily::query()->orderBy('kind')->orderBy('dimension')->orderBy('channel')->orderBy('value')->get()
        ->mapWithKeys(fn (TrafficDaily $row): array => ["{$row->kind}.{$row->dimension}.{$row->channel}.{$row->value}" => $row->only(['visitors', 'page_views', 'entries', 'leads', 'hits', 'verified_hits', 'spoofed_hits', 'errors_4xx', 'errors_5xx'])])
        ->all();
}

it('rolls a day up into totals, channels, sources, devices, locales and bots', function () {
    seedRawDay($this->at);

    $result = app(AggregateTraffic::class)->handle($this->date);
    $daily = dailySnapshot();

    expect($result['daily'])->toBe(count($daily))
        ->and($daily['visits.total..'])->toMatchArray(['visitors' => 4, 'page_views' => 5, 'entries' => 4, 'leads' => 1, 'errors_4xx' => 1])
        ->and($daily['visits.channel..ai'])->toMatchArray(['visitors' => 2, 'page_views' => 3, 'entries' => 2, 'leads' => 1])
        ->and($daily['visits.channel..search'])->toMatchArray(['visitors' => 1, 'leads' => 0])
        ->and($daily['visits.source.ai.chatgpt.com'])->toMatchArray(['visitors' => 2, 'leads' => 1])
        ->and($daily['visits.source.search.google.com'])->toMatchArray(['visitors' => 1])
        ->and($daily)->not->toHaveKey('visits.source.direct.')
        ->and($daily['visits.device..mobile'])->toMatchArray(['visitors' => 1])
        ->and($daily['visits.device..desktop'])->toMatchArray(['visitors' => 3])
        ->and($daily['visits.locale..ru'])->toMatchArray(['visitors' => 1])
        ->and($daily['bots.bot..gptbot'])->toMatchArray(['hits' => 2, 'visitors' => 2, 'verified_hits' => 1, 'spoofed_hits' => 1])
        ->and($daily['bots.bot..googlebot'])->toMatchArray(['hits' => 1, 'errors_4xx' => 1, 'verified_hits' => 0]);

    $page = TrafficDailyPage::query()->where('kind', TrafficDaily::KIND_VISITS)->where('path', '/uz/product/zd421')->sole();

    expect($page->hits)->toBe(2)
        ->and($page->visitors)->toBe(2)
        ->and($page->product_id)->toBe(7)
        ->and(TrafficDailyPage::query()->where('kind', TrafficDaily::KIND_BOTS)->where('bot', 'googlebot')->sole())
        ->path->toBe('/en/missing')->status->toBe(404);
});

it('is idempotent and replaces the day when raw rows change', function () {
    seedRawDay($this->at);

    app(AggregateTraffic::class)->handle($this->date);
    $first = dailySnapshot();
    $pages = TrafficDailyPage::count();

    app(AggregateTraffic::class)->handle($this->date);

    expect(dailySnapshot())->toBe($first)
        ->and(TrafficDailyPage::count())->toBe($pages);

    // A crawler verified later: re-running updates the counts instead of adding rows.
    BotHit::query()->where('bot', 'googlebot')->update(['verified' => true]);
    app(AggregateTraffic::class)->handle($this->date);

    expect(dailySnapshot()['bots.bot..googlebot']['verified_hits'])->toBe(1)
        ->and(TrafficDaily::count())->toBe(count($first));
});

it('counts days in the Tashkent timezone and leaves other days alone', function () {
    // 23:30 Tashkent on the 20th is 18:30 UTC; 00:30 Tashkent on the 21st is 19:30 UTC on the 20th.
    SiteVisit::factory()->create(['occurred_at' => CarbonImmutable::parse('2026-10-20 23:30', $this->tz)->utc()]);
    SiteVisit::factory()->create(['occurred_at' => CarbonImmutable::parse('2026-10-21 00:30', $this->tz)->utc()]);
    TrafficDaily::factory()->create(['date' => '2026-10-19', 'visitors' => 9]);

    app(AggregateTraffic::class)->handle('2026-10-20');

    expect(TrafficDaily::query()->where('date', '2026-10-20')->where('dimension', 'total')->sole()->page_views)->toBe(1)
        ->and(TrafficDaily::query()->where('date', '2026-10-19')->sole()->visitors)->toBe(9);
});

it('keeps only the top pages per day and kind', function () {
    config(['analytics.top_pages' => 2]);

    foreach (['/a' => 3, '/b' => 2, '/c' => 1] as $path => $count) {
        SiteVisit::factory()->count($count)->create(['occurred_at' => $this->at, 'path' => $path]);
    }

    app(AggregateTraffic::class)->handle($this->date);

    expect(TrafficDailyPage::query()->orderByDesc('hits')->pluck('path')->all())->toBe(['/a', '/b']);
});

it('aggregates today from artisan', function () {
    SiteVisit::factory()->create();

    $this->artisan('analytics:aggregate')->assertSuccessful();
    $this->artisan('analytics:aggregate', ['--yesterday' => true])->assertSuccessful();
    $this->artisan('analytics:aggregate', ['date' => 'yesterday'])->assertFailed();

    expect(TrafficDaily::query()->where('date', TrafficRecorder::today())->where('dimension', 'total')->sole()->page_views)->toBe(1);
});

it('prunes raw rows and aggregates past their retention', function () {
    $this->freezeTime();

    $oldVisit = SiteVisit::factory()->create(['occurred_at' => now()->subDays(31)]);
    $keptVisit = SiteVisit::factory()->create(['occurred_at' => now()->subDays(29)]);
    $oldHit = BotHit::factory()->create(['occurred_at' => now()->subDays(15)]);
    $keptHit = BotHit::factory()->create(['occurred_at' => now()->subDays(13)]);
    $oldDaily = TrafficDaily::factory()->create(['date' => now()->subMonths(26)->toDateString()]);
    $keptDaily = TrafficDaily::factory()->create(['date' => now()->subMonths(24)->toDateString()]);
    $oldPage = TrafficDailyPage::factory()->create(['date' => now()->subMonths(26)->toDateString()]);

    $deleted = app(PruneAnalytics::class)->handle();

    expect($deleted)->toBe(['site_visits' => 1, 'bot_hits' => 1, 'traffic_daily' => 1, 'traffic_daily_pages' => 1])
        ->and(SiteVisit::find($oldVisit->id))->toBeNull()
        ->and(SiteVisit::find($keptVisit->id))->not->toBeNull()
        ->and(BotHit::find($oldHit->id))->toBeNull()
        ->and(BotHit::find($keptHit->id))->not->toBeNull()
        ->and(TrafficDaily::find($oldDaily->id))->toBeNull()
        ->and(TrafficDaily::find($keptDaily->id))->not->toBeNull()
        ->and(TrafficDailyPage::find($oldPage->id))->toBeNull();

    $this->artisan('analytics:prune')->assertSuccessful();
});

it('runs one aggregation per day at a time and skips while another holds the lock', function () {
    config(['analytics.aggregate_lock_wait' => 0]);
    seedRawDay($this->at);

    $lock = Cache::lock("analytics:aggregate:{$this->date}", 120);
    expect($lock->get())->toBeTrue();

    expect(app(AggregateTraffic::class)->handle($this->date))->toBe(['daily' => 0, 'pages' => 0, 'skipped' => true])
        ->and(TrafficDaily::count())->toBe(0);

    $this->artisan('analytics:aggregate', ['date' => $this->date])->assertSuccessful()->expectsOutputToContain('skipped');

    $lock->release();

    expect(app(AggregateTraffic::class)->handle($this->date)['skipped'])->toBeFalse()
        ->and(TrafficDaily::count())->toBeGreaterThan(0);
});

it('schedules aggregation, verification, pruning and the range refresh without overlaps', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'analytics:'))
        ->mapWithKeys(fn ($event) => [trim(str_replace("'", '', Str::after((string) $event->command, 'artisan'))) => [$event->expression, $event->timezone, $event->withoutOverlapping, $event->expiresAt]]);

    $tz = TrafficRecorder::timezone();

    expect($events->all())->toBe([
        'analytics:aggregate' => ['*/15 * * * *', config('app.timezone'), true, 10],
        'analytics:verify-bots' => ['20 0 * * *', $tz, true, 10],
        'analytics:prune' => ['10 1 * * *', $tz, true, 10],
        'analytics:refresh-bot-ranges' => ['30 3 * * 1', $tz, true, 10],
    ]);
});

it('counts add to cart rows in add_to_carts, not as page views or leads', function () {
    SiteVisit::factory()->create(['occurred_at' => $this->at, 'visitor_hash' => 'cccccccccccccccc']);
    SiteVisit::factory()->count(2)->create(['occurred_at' => $this->at, 'visitor_hash' => 'cccccccccccccccc', 'type' => VisitType::AddToCart, 'is_entry' => false, 'product_id' => 7]);

    app(AggregateTraffic::class)->handle($this->date);

    $total = TrafficDaily::query()->where('kind', TrafficDaily::KIND_VISITS)->where('dimension', TrafficDaily::DIM_TOTAL)->sole();

    expect($total->add_to_carts)->toBe(2)
        ->and($total->page_views)->toBe(1)
        ->and($total->leads)->toBe(0);
});
