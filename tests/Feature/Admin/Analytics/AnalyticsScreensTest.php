<?php

use App\Enums\Role;
use App\Livewire\Admin\Analytics\AiVisibility;
use App\Livewire\Admin\Analytics\Traffic;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SiteVisit;
use App\Models\TrafficDaily;
use App\Models\TrafficDailyPage;
use App\Support\Analytics\TrafficRecorder;
use App\Support\Analytics\TrafficReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Aggregates for yesterday: totals, channels (AI with a lead), sources,
 * devices, locales, top pages, top products and two crawlers (one spoofed hit).
 *
 * @return list<Product>
 */
function seedAggregates(int $products = 3, int $daysAgo = 1): array
{
    $date = CarbonImmutable::parse(TrafficRecorder::today())->subDays($daysAgo)->toDateString();

    TrafficDaily::factory()->create(['date' => $date, 'visitors' => 120, 'page_views' => 400, 'entries' => 130, 'leads' => 6]);
    TrafficDaily::factory()->create(['date' => $date, 'dimension' => 'channel', 'value' => 'ai', 'visitors' => 20, 'leads' => 3]);
    TrafficDaily::factory()->create(['date' => $date, 'dimension' => 'channel', 'value' => 'search', 'visitors' => 70, 'leads' => 2]);
    TrafficDaily::factory()->create(['date' => $date, 'dimension' => 'channel', 'value' => 'direct', 'visitors' => 30, 'leads' => 1]);
    TrafficDaily::factory()->create(['date' => $date, 'dimension' => 'source', 'channel' => 'ai', 'value' => 'chatgpt.com', 'visitors' => 15, 'leads' => 3]);
    TrafficDaily::factory()->create(['date' => $date, 'dimension' => 'source', 'channel' => 'search', 'value' => 'google.com', 'visitors' => 60, 'leads' => 2]);
    TrafficDaily::factory()->create(['date' => $date, 'dimension' => 'device', 'value' => 'mobile', 'visitors' => 80]);
    TrafficDaily::factory()->create(['date' => $date, 'dimension' => 'locale', 'value' => 'ru', 'visitors' => 70]);
    TrafficDaily::factory()->bot('gptbot', 40)->create(['date' => $date, 'verified_hits' => 39, 'spoofed_hits' => 1, 'errors_4xx' => 2]);
    TrafficDaily::factory()->bot('bytespider', 12)->create(['date' => $date]);

    TrafficDailyPage::factory()->create(['date' => $date, 'path' => '/uz/catalog', 'hits' => 90]);
    TrafficDailyPage::factory()->create(['date' => $date, 'kind' => 'bots', 'bot' => 'gptbot', 'path' => '/uz/old-scanners', 'status' => 404, 'hits' => 2]);
    TrafficDailyPage::factory()->create(['date' => $date, 'kind' => 'bots', 'bot' => 'gptbot', 'path' => '/uz/catalog', 'hits' => 30]);

    $created = Product::factory()->count($products)->create();

    foreach ($created as $index => $product) {
        TrafficDailyPage::factory()->create(['date' => $date, 'path' => '/uz/product/'.$product->slug, 'product_id' => $product->id, 'hits' => 50 - $index]);
        TrafficDailyPage::factory()->create(['date' => $date, 'kind' => 'bots', 'bot' => 'gptbot', 'path' => '/uz/product/'.$product->slug, 'product_id' => $product->id, 'hits' => 5]);
    }

    return $created->all();
}

it('is forbidden for every role but admin', function (Role $role, string $component) {
    $this->actingAs(staffUser($role));

    Livewire::test($component)->assertForbidden();
})->with([
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
    'service engineer' => [Role::ServiceEngineer],
])->with([
    'traffic' => [Traffic::class],
    'ai visibility' => [AiVisibility::class],
]);

it('returns 403 over HTTP for non-admins and 200 for the admin', function (string $route) {
    $this->actingAs(staffUser(Role::SalesManager))->get(route($route))->assertForbidden();
    $this->actingAs(staffUser(Role::Admin))->get(route($route))->assertOk();
})->with(['admin.analytics.index', 'admin.analytics.ai']);

it('shows the analytics items in the menu for the admin only', function () {
    $html = $this->actingAs(staffUser(Role::Admin))->get(route('admin.analytics.index'))->assertOk()->getContent();

    expect($html)->toContain('data-menu-item="analytics"')->toContain('data-menu-item="ai-visibility"');

    $html = $this->actingAs(staffUser(Role::ContentManager))->get('/admin')->assertOk()->getContent();

    expect($html)->not->toContain('data-menu-item="analytics"')->not->toContain('data-menu-item="ai-visibility"');
});

it('renders the traffic dashboard from the aggregates', function () {
    $products = seedAggregates();
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(Traffic::class)
        ->assertOk()
        ->assertSeeHtml('data-kpi="visitors"')
        ->assertSee('120')
        ->assertSee('400')
        ->assertSee('5%') // 6 leads / 120 visitors
        ->assertSeeHtml('data-chart="visitors"')
        ->assertSeeHtml('data-lead-marker')
        ->assertSeeHtml('data-channel="ai"')
        ->assertSee(__('analytics.enums.traffic_channel.ai'))
        ->assertSeeHtml('data-source="chatgpt.com"')
        ->assertSee('/uz/catalog')
        ->assertSeeHtml('data-product="'.$products[0]->id.'"')
        ->assertSee($products[0]->sku)
        ->assertSee(__('analytics.enums.device_type.mobile'))
        ->assertDontSeeHtml('data-empty')
        ->assertDontSeeHtml('data-external-analytics');
});

it('switches the range and rejects unknown ranges', function () {
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(Traffic::class)
        ->assertSet('days', 30)
        ->call('setDays', 7)->assertSet('days', 7)
        ->call('setDays', 365)->assertSet('days', 30)
        ->set('days', 90)->assertSet('days', 90);

    Livewire::withQueryParams(['days' => 12])->test(Traffic::class)->assertSet('days', 30);
});

it('shows an empty state without data', function () {
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(Traffic::class)->assertSeeHtml('data-empty')->assertSee(__('analytics.traffic.empty'));
    Livewire::test(AiVisibility::class)->assertSeeHtml('data-empty')->assertSee(__('analytics.ai.no_bots'));
});

it('links to GA4 and Metrika when their IDs are set', function () {
    Setting::factory()->setting('tracking', ['enabled' => true, 'ga4' => 'G-AB12CD34EF', 'metrika' => '98765432'])->create();
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(Traffic::class)
        ->assertSeeHtml('data-external-analytics')
        ->assertSeeHtml('href="https://analytics.google.com/analytics/web/"')
        ->assertSeeHtml('href="https://metrika.yandex.ru/dashboard?id=98765432"');
});

it('never aggregates on render: today comes from the 15-minute schedule', function () {
    SiteVisit::factory()->entry()->create();
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(Traffic::class)->assertSeeHtml('data-empty');

    expect(TrafficDaily::count())->toBe(0);

    $this->artisan('analytics:aggregate')->assertSuccessful();
    Cache::flush();

    Livewire::test(Traffic::class)->assertDontSeeHtml('data-empty');
});

it('caches the report queries for five minutes', function () {
    $this->actingAs(staffUser(Role::Admin));
    seedAggregates(1);

    Livewire::test(Traffic::class)->assertSee('120');

    TrafficDaily::query()->where('dimension', 'total')->update(['visitors' => 777]);

    Livewire::test(Traffic::class)->assertSee('120')->assertDontSee('777');

    $this->travel(6)->minutes();

    Livewire::test(Traffic::class)->assertSee('777');
});

it('lists only client errors (4xx) as not found, and shows deleted products as plain labels', function () {
    $products = seedAggregates(2);
    $date = CarbonImmutable::parse(TrafficRecorder::today())->subDay()->toDateString();
    TrafficDailyPage::factory()->create(['date' => $date, 'kind' => 'bots', 'bot' => 'gptbot', 'path' => '/uz/broken', 'status' => 503, 'hits' => 9]);
    $products[0]->delete();
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(AiVisibility::class)
        ->assertSee('/uz/old-scanners')
        ->assertDontSee('/uz/broken')
        ->assertSeeHtml('data-trashed')
        ->assertDontSeeHtml('href="'.route('admin.products.edit', $products[0]).'"')
        ->assertSeeHtml('href="'.route('admin.products.edit', $products[1]).'"');
});

it('shows IP counts only for crawlers that can be verified', function () {
    seedAggregates(1);
    $this->actingAs(staffUser(Role::Admin));

    $html = Livewire::test(AiVisibility::class)->html();

    preg_match('/data-bot="bytespider".*?data-ips>([^<]*)</s', $html, $bytespider);
    preg_match('/data-bot="gptbot".*?data-ips>([^<]*)</s', $html, $gptbot);

    expect(trim($bytespider[1]))->toBe('—')
        ->and(trim($gptbot[1]))->toBe('1');
});

it('renders the AI visibility screen per crawler', function () {
    $products = seedAggregates();
    $this->actingAs(staffUser(Role::Admin));

    Livewire::test(AiVisibility::class)
        ->assertOk()
        ->assertSeeHtml('data-bot="gptbot"')
        ->assertSeeHtml('data-bot="bytespider"')
        ->assertSeeHtml('data-verified="39"')
        ->assertSeeHtml('data-spoofed="1"')
        ->assertSeeHtml('data-sparkline')
        ->assertSee(__('analytics.enums.bot_verification.ua_only'))
        ->assertSee('/uz/old-scanners')
        ->assertSeeHtml('data-product="'.$products[0]->id.'"')
        ->assertSeeHtml('data-comparison="ai"')
        ->assertSeeHtml('data-ai-source="chatgpt.com"')
        // Filter by one crawler; an unknown key falls back to all.
        ->set('bot', 'bytespider')
        ->assertSet('bot', 'bytespider')
        ->assertDontSee('/uz/old-scanners')
        ->set('bot', 'evil')
        ->assertSet('bot', '');
});

it('loads the top products without N+1 queries', function (string $component) {
    $this->actingAs(staffUser(Role::Admin));
    Model::preventLazyLoading();

    // Measured with an empty report cache each time, after a warm-up render
    // (permissions and settings load once per process).
    $measure = function () use ($component): int {
        Cache::flush();

        return countQueries(fn () => Livewire::test($component)->assertOk());
    };

    Livewire::test($component)->assertOk();

    seedAggregates(2);
    $few = $measure();

    seedAggregates(8, daysAgo: 2);
    $many = $measure();

    expect($many)->toBe($few);
})->with([
    'traffic' => [Traffic::class],
    'ai visibility' => [AiVisibility::class],
]);

it('shows Latin Uzbek month names on the admin screens', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00'));
    $admin = staffUser(Role::Admin);
    $admin->update(['locale' => 'uz']);
    seedAggregates(1);

    $dashboard = $this->actingAs($admin)->get('/admin')->assertOk()->getContent();
    $ai = $this->actingAs($admin)->get(route('admin.analytics.ai'))->assertOk()->getContent();

    expect($dashboard)->toContain('Oktabr')->not->toContain('октябр')
        ->and($ai)->toContain('Okt')->not->toContain('окт');
});

it('reports top categories by hits with names loaded in one query', function () {
    $date = CarbonImmutable::parse(TrafficRecorder::today())->subDay()->toDateString();
    $a = Category::factory()->create();
    $b = Category::factory()->create();

    TrafficDailyPage::factory()->create(['date' => $date, 'category_id' => $a->id, 'hits' => 5, 'visitors' => 2]);
    TrafficDailyPage::factory()->create(['date' => $date, 'category_id' => $b->id, 'hits' => 9, 'visitors' => 4]);
    TrafficDailyPage::factory()->create(['date' => $date, 'category_id' => $b->id, 'hits' => 1, 'visitors' => 1]);
    TrafficDailyPage::factory()->create(['date' => $date, 'category_id' => $a->id, 'status' => 404, 'hits' => 99]);
    TrafficDailyPage::factory()->create(['date' => $date, 'category_id' => null, 'hits' => 99]);

    $rows = (new TrafficReport(7))->topCategories(TrafficDaily::KIND_VISITS, null, 500);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['category']->is($b))->toBeTrue()
        ->and($rows[0]['hits'])->toBe(10)
        ->and($rows[1]['hits'])->toBe(5);

    expect((new TrafficReport(7))->topCategories(TrafficDaily::KIND_VISITS, null, 1))->toHaveCount(1);
});
