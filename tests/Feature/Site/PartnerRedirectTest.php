<?php

use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\TrafficChannel;
use App\Enums\VisitType;
use App\Models\BotHit;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SiteVisit;
use App\Support\Analytics\TrafficRecorder;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

const PARTNER_URL = 'https://partner.example.uz/signup?ref=poson&utm_source=poson&utm_medium=site&x=%20a+b';
const PARTNER_HUMAN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const PARTNER_GPTBOT_UA = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';

beforeEach(function () {
    config(['analytics.enabled' => true]);
});

function partnerProduct(array $attributes = []): Product
{
    return Product::factory()->withPartnerLink(PARTNER_URL)->create(['slug' => 'billz', ...$attributes]);
}

it('records a partner click with attribution and redirects 302 to the brand website exactly as entered', function () {
    $product = partnerProduct();

    $this->withHeaders(['User-Agent' => PARTNER_HUMAN_UA])->get('/ru/product/billz?utm_source=chatgpt.com')->assertOk();

    $this->withHeaders(['User-Agent' => PARTNER_HUMAN_UA, 'Referer' => url('/ru/product/billz')])
        ->get('/ru/go/billz')
        ->assertStatus(302)
        ->assertHeader('Location', PARTNER_URL)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $click = SiteVisit::query()->where('type', VisitType::PartnerClick)->sole();

    expect($click->product_id)->toBe($product->id)
        ->and($click->channel)->toBe(TrafficChannel::Ai)
        ->and($click->source)->toBe('chatgpt.com')
        ->and($click->path)->toBe('/ru/go/billz')
        ->and($click->route_name)->toBe('site.product.partner')
        ->and($click->locale)->toBe('ru')
        ->and($click->status)->toBe(302)
        // The redirect is not also a page view.
        ->and(SiteVisit::query()->where('type', VisitType::PageView)->count())->toBe(1);
});

it('still redirects when analytics is off and records nothing', function () {
    config(['analytics.enabled' => false]);
    partnerProduct();

    $this->get('/uz/go/billz')->assertRedirect(PARTNER_URL);

    expect(SiteVisit::count())->toBe(0);
});

it('never fails the redirect because of analytics', function () {
    Exceptions::fake();
    partnerProduct();
    // Breaks the analytics insert (missing table).
    Schema::drop('site_visits');

    $this->withHeaders(['User-Agent' => PARTNER_HUMAN_UA])->get('/uz/go/billz')->assertRedirect(PARTNER_URL);
});

it('does not count staff or crawlers as partner clicks', function () {
    partnerProduct();

    $this->actingAs(staffUser(Role::SalesManager))->withHeaders(['User-Agent' => PARTNER_HUMAN_UA])->get('/uz/go/billz')->assertRedirect(PARTNER_URL);
    auth()->logout();
    $this->withHeaders(['User-Agent' => PARTNER_GPTBOT_UA])->get('/en/go/billz')->assertRedirect(PARTNER_URL);

    expect(SiteVisit::count())->toBe(0)
        ->and(BotHit::query()->where('path', '/en/go/billz')->count())->toBe(1);
});

it('returns 404 for a product that is not on the site', function (string $state) {
    $product = partnerProduct();

    match ($state) {
        'inactive' => $product->update(['is_active' => false]),
        'trashed' => $product->delete(),
        'hidden category' => Category::query()->whereKey($product->category_id)->update(['is_active' => false]),
        'hidden brand' => Brand::query()->whereKey($product->brand_id)->update(['is_active' => false]),
        'not software' => $product->update(['type' => ProductType::Hardware]),
    };

    $this->get('/uz/go/billz')->assertNotFound();

    expect(SiteVisit::query()->where('type', VisitType::PartnerClick)->count())->toBe(0);
})->with(['inactive', 'trashed', 'hidden category', 'hidden brand', 'not software']);

it('returns 404 without a valid https brand website', function (?string $url) {
    Product::factory()->software()->for(Brand::factory()->state(['website' => $url]))->create(['slug' => 'billz']);

    $this->get('/uz/go/billz')->assertNotFound();
})->with([
    'missing' => [null],
    'http' => ['http://partner.example.uz/signup'],
    'relative' => ['/signup'],
    'javascript' => ['javascript:alert(1)'],
    'credentials' => ['https://user:pass@partner.example.uz/'],
]);

it('returns 404 for an unknown product or locale, and for software without a brand', function () {
    partnerProduct();
    Product::factory()->software()->withoutBrand()->create(['slug' => 'no-brand']);

    $this->get('/uz/go/no-brand')->assertNotFound();

    $this->get('/uz/go/unknown')->assertNotFound();
    $this->get('/de/go/billz')->assertNotFound();
});

it('is throttled', function () {
    partnerProduct();

    foreach (range(1, 30) as $attempt) {
        $this->get('/uz/go/billz')->assertRedirect();
    }

    $this->get('/uz/go/billz')->assertStatus(429);
});

it('keeps crawlers out of the partner redirects in robots.txt', function () {
    $robots = $this->get('/robots.txt')->assertOk()->getContent();

    expect($robots)->toContain("Disallow: /*/go/\n");
});

it('records the click under the day the recorder uses', function () {
    $product = partnerProduct();

    $this->withHeaders(['User-Agent' => PARTNER_HUMAN_UA])->get('/en/go/billz');

    $click = SiteVisit::sole();

    expect($click->type)->toBe(VisitType::PartnerClick)
        ->and($click->occurred_at->clone()->timezone(TrafficRecorder::timezone())->toDateString())->toBe(TrafficRecorder::today())
        ->and($click->product_id)->toBe($product->id);
});
