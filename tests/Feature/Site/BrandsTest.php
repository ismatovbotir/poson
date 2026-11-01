<?php

use App\Actions\Catalog\SetProductActive;
use App\Livewire\Site\Brands\Show;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// These tests cover the site open for indexing (plan 15: closed by default).
beforeEach(function () {
    openSearchIndexing();
});

/**
 * Zebra (2 visible products in two sections, 1 draft), Honeywell (1 visible),
 * Empty (active, only a draft), Hidden (inactive, 1 product), Orphan (in a hidden category).
 *
 * @return array<string, mixed>
 */
function brandsFixture(): array
{
    $printers = Category::factory()->create(['slug' => 'printers', 'name' => ['uz' => 'Printerlar', 'ru' => 'Принтеры', 'en' => 'Printers'], 'sort' => 1]);
    $scanners = Category::factory()->create(['slug' => 'scanners', 'name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners'], 'sort' => 2]);
    $handheld = Category::factory()->child($scanners)->create(['slug' => 'handheld']);
    $hiddenCategory = Category::factory()->create(['slug' => 'hidden', 'is_active' => false]);

    $zebra = Brand::factory()->create(['name' => 'Zebra', 'slug' => 'zebra', 'sort' => 2, 'website' => 'https://www.zebra.com', 'description' => ['uz' => '<p>Zebra uz tavsif</p>', 'ru' => '<p>Zebra ru описание</p>', 'en' => '<p>Zebra en description</p>']]);
    $honeywell = Brand::factory()->create(['name' => 'Honeywell', 'slug' => 'honeywell', 'sort' => 1]);
    $empty = Brand::factory()->create(['name' => 'Empty', 'slug' => 'empty']);
    $hidden = Brand::factory()->inactive()->create(['name' => 'Hidden', 'slug' => 'hidden-brand']);
    $orphan = Brand::factory()->create(['name' => 'Orphan', 'slug' => 'orphan']);

    Product::factory()->inCategory($printers)->for($zebra)->create(['name' => ['uz' => 'ZD421 uz', 'ru' => 'ZD421 ru', 'en' => 'ZD421 en']]);
    Product::factory()->inCategory($handheld)->for($zebra)->create(['name' => ['uz' => 'DS2208 uz', 'ru' => 'DS2208 ru', 'en' => 'DS2208 en']]);
    Product::factory()->inCategory($printers)->for($zebra)->inactive()->create(['name' => ['uz' => 'Draft uz', 'ru' => 'Draft ru', 'en' => 'Draft en']]);
    Product::factory()->inCategory($scanners)->for($honeywell)->create();
    Product::factory()->inCategory($scanners)->for($empty)->inactive()->create();
    Product::factory()->inCategory($scanners)->for($hidden)->create();
    Product::factory()->inCategory($hiddenCategory)->for($orphan)->create();

    return compact('printers', 'scanners', 'handheld', 'zebra', 'honeywell', 'empty', 'hidden', 'orphan');
}

describe('brands index', function () {
    it('lists only active brands with visible products, in sort order, with counts', function (string $locale, string $title) {
        brandsFixture();

        $html = $this->get("/{$locale}/brands")->assertOk()->assertSeeText($title)->getContent();

        preg_match_all('#data-brand-card="([^"]+)"#', $html, $matches);

        expect($matches[1])->toBe(['honeywell', 'zebra'])
            ->and($html)->toContain('href="'.url("/{$locale}/brands/zebra").'"')
            ->toContain('data-brand-count="2"')
            ->toContain('data-brand-count="1"')
            ->not->toContain('<meta name="robots"');

        expectNoBannedWording($html);
    })->with([
        'uz' => ['uz', 'Brendlar'],
        'ru' => ['ru', 'Бренды'],
        'en' => ['en', 'Brands'],
    ]);

    it('prints the SEO title, hreflang and BreadcrumbList', function () {
        brandsFixture();

        $html = $this->get('/ru/brands')->assertOk()->getContent();
        $breadcrumbs = collect(jsonLdBlocks($html))->firstWhere('@type', 'BreadcrumbList');

        expect($html)->toContain('<title>'.e(__('site.brands.seo_title', [], 'ru')).'</title>')
            ->toContain('<link rel="canonical" href="'.url('/ru/brands').'">')
            ->toContain('hreflang="en" href="'.url('/en/brands').'"')
            ->and(array_column($breadcrumbs['itemListElement'], 'name'))->toBe([__('site.breadcrumbs.home', [], 'ru'), 'Бренды'])
            ->and($breadcrumbs['itemListElement'][1]['item'])->toBe(url('/ru/brands'));
    });

    it('shows an empty, noindex list without brands', function () {
        $this->get('/en/brands')->assertOk()->assertSee('data-brands-empty', false)->assertSee('<meta name="robots" content="noindex,follow">', false);
    });

    it('renders with a bounded number of queries', function () {
        brandsFixture();
        $this->get('/en/brands')->assertOk();

        $few = countQueries(fn () => $this->get('/en/brands')->assertOk());

        $category = Category::query()->where('slug', 'printers')->first();
        foreach (range(1, 6) as $index) {
            Product::factory()->count(2)->inCategory($category)->for(Brand::factory())->create();
        }

        expect(countQueries(fn () => $this->get('/en/brands')->assertOk()))->toBe($few);
    });
});

describe('brand page', function () {
    it('shows the description and only the visible products, with SEO title, canonical and hreflang', function (string $locale) {
        brandsFixture();

        $html = $this->get("/{$locale}/brands/zebra")->assertOk()->getContent();

        expect($html)->toContain("ZD421 {$locale}")
            ->toContain("DS2208 {$locale}")
            ->not->toContain("Draft {$locale}")
            ->toContain('data-total="2"')
            ->toContain('data-brand-description')
            ->toContain('<link rel="canonical" href="'.url("/{$locale}/brands/zebra").'">')
            ->toContain('hreflang="uz" href="'.url('/uz/brands/zebra').'"')
            ->toContain('<title>'.e(__('site.brands.show.seo_title', ['name' => 'Zebra'], $locale)).'</title>');

        expectNoBannedWording($html);
    })->with(['uz', 'ru', 'en']);

    it('returns 404 for inactive and unknown brands', function () {
        brandsFixture();

        $this->get('/en/brands/hidden-brand')->assertNotFound();
        $this->get('/en/brands/no-such-brand')->assertNotFound();
    });

    it('shows an empty, noindex page for an active brand without visible products', function () {
        brandsFixture();

        $this->get('/en/brands')->assertDontSee('data-brand-card="empty"', false);

        $this->get('/en/brands/empty')->assertOk()
            ->assertSee('data-empty', false)
            ->assertSee('<meta name="robots" content="noindex,follow">', false);
    });

    it('prints Brand and BreadcrumbList JSON-LD', function () {
        brandsFixture();

        $blocks = collect(jsonLdBlocks($this->get('/en/brands/zebra')->assertOk()->getContent()));
        $brand = $blocks->firstWhere('@type', 'Brand');
        $breadcrumbs = $blocks->firstWhere('@type', 'BreadcrumbList');

        expect($brand)->toMatchArray(['name' => 'Zebra', 'url' => url('/en/brands/zebra'), 'description' => 'Zebra en description', 'sameAs' => ['https://www.zebra.com']])
            ->and(array_column($breadcrumbs['itemListElement'], 'item'))->toBe([url('/en'), url('/en/brands'), url('/en/brands/zebra')]);
    });

    it('links the official website of a hardware brand only', function () {
        brandsFixture();

        $this->get('/en/brands/zebra')->assertSee('data-brand-website', false)->assertSee('href="https://www.zebra.com"', false);

        $software = Category::factory()->create(['slug' => 'software']);
        $vendor = Brand::factory()->create(['slug' => 'vendor', 'website' => 'https://partner.example.uz/signup?ref=poson']);
        $product = Product::factory()->software()->inCategory($software)->for($vendor)->create();

        $html = $this->get('/en/brands/vendor')->assertOk()->getContent();
        $brand = collect(jsonLdBlocks($html))->firstWhere('@type', 'Brand');

        expect($html)->not->toContain('data-brand-website')
            ->not->toContain('href="https://partner.example.uz')
            ->toContain('href="'.url("/en/go/{$product->slug}").'"')
            ->and($brand)->not->toHaveKey('sameAs');
    });

    it('strips unsafe markup from a stored description', function () {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create(['slug' => 'unsafe', 'description' => ['uz' => '<p>Matn</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">havola</a>']]);
        Product::factory()->inCategory($category)->for($brand)->create();

        $html = $this->get('/uz/brands/unsafe')->assertOk()->getContent();
        preg_match('#<div class="[^"]*" data-brand-description>(.*?)</div>#s', $html, $description);

        expect($description[1])->toContain('Matn')->toContain('havola')
            ->not->toContain('<script')
            ->not->toContain('onerror')
            ->not->toContain('javascript:')
            ->and($html)->not->toContain('alert(1)')
            ->and(collect(jsonLdBlocks($html))->firstWhere('@type', 'Brand')['description'])->not->toContain('<');
    });

    it('hides http and javascript: websites, without sameAs', function (string $website) {
        $category = Category::factory()->create();
        $brand = Brand::factory()->create(['slug' => 'insecure', 'website' => $website]);
        Product::factory()->inCategory($category)->for($brand)->create();

        $html = $this->get('/en/brands/insecure')->assertOk()->getContent();

        expect($html)->not->toContain('data-brand-website')
            ->not->toContain('href="'.e($website).'"')
            ->and(collect(jsonLdBlocks($html))->firstWhere('@type', 'Brand'))->not->toHaveKey('sameAs');
    })->with(['http' => 'http://www.zebra.com', 'javascript' => 'javascript:alert(1)']);

    it('opens the official website in a new tab without nofollow', function () {
        brandsFixture();

        $html = $this->get('/en/brands/zebra')->assertOk()->getContent();

        expect($html)->toMatch('#<a href="https://www\.zebra\.com" target="_blank" rel="noopener noreferrer" [^>]*data-brand-website>#')
            ->toContain('www.zebra.com');
    });

    it('returns 404 for a page past the end', function () {
        brandsFixture();

        $this->get('/en/brands/zebra?page=99')->assertNotFound();
    });

    it('ignores a malformed category filter', function () {
        brandsFixture();

        $this->get('/en/brands/zebra?category[]=printers')->assertOk()->assertSee('data-total="2"', false);
    });

    it('filters by catalog section', function () {
        brandsFixture();

        $html = $this->get('/en/brands/zebra')->getContent();

        expect($html)->toContain('data-brand-section="printers"')->toContain('data-brand-section="scanners"');

        app()->setLocale('uz');

        Livewire::withQueryParams(['category' => 'scanners'])
            ->test(Show::class, ['brand' => Brand::query()->where('slug', 'zebra')->first()])
            ->assertSee('DS2208 uz')
            ->assertDontSee('ZD421 uz')
            ->call('filterCategory', 'printers')
            ->assertSee('ZD421 uz')
            ->assertDontSee('DS2208 uz')
            ->call('filterCategory', 'unknown')
            ->assertSet('category', '')
            ->assertSee('DS2208 uz');

        $this->get('/en/brands/zebra?category=scanners')->assertOk()->assertSee('<meta name="robots" content="noindex,follow">', false);
    });

    it('renders with a bounded number of queries', function () {
        $fixture = brandsFixture();
        $this->get('/en/brands/zebra')->assertOk();

        $few = countQueries(fn () => $this->get('/en/brands/zebra')->assertOk());

        Product::factory()->count(6)->inCategory($fixture['printers'])->for($fixture['zebra'])->create();

        expect(countQueries(fn () => $this->get('/en/brands/zebra')->assertOk()))->toBe($few);
    });

    it('links the brand from the product page', function () {
        brandsFixture();
        $product = Product::query()->where('name->en', 'ZD421 en')->first();

        $this->get("/en/product/{$product->slug}")->assertOk()->assertSee('href="'.url('/en/brands/zebra').'"', false);
    });
});

describe('sitemap and llms.txt', function () {
    it('lists the brands list and the listed brands in every locale', function () {
        brandsFixture();

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        foreach (['uz', 'ru', 'en'] as $locale) {
            expect($xml)->toContain('<loc>'.url("/{$locale}/brands").'</loc>')
                ->toContain('<loc>'.url("/{$locale}/brands/zebra").'</loc>')
                ->toContain('<loc>'.url("/{$locale}/brands/honeywell").'</loc>');
        }

        expect($xml)->not->toContain('/brands/empty<')
            ->not->toContain('/brands/hidden-brand<')
            ->not->toContain('/brands/orphan<');
    });

    it('lists the brands in llms.txt', function () {
        brandsFixture();

        $text = $this->get('/llms.txt')->assertOk()->getContent();

        expect($text)->toContain('## Brands')
            ->toContain('[All brands]('.url('/en/brands').')')
            ->toContain('[Zebra]('.url('/en/brands/zebra').')')
            ->not->toContain('/brands/empty)')
            ->not->toContain('/brands/hidden-brand)');
    });

    it('drops a brand when its only product is unpublished or its category is hidden', function () {
        $fixture = brandsFixture();
        $this->get('/sitemap.xml');

        app(SetProductActive::class)->handle(Product::query()->where('brand_id', $fixture['honeywell']->id)->sole(), false);

        expect($this->get('/en/brands')->getContent())->not->toContain('data-brand-card="honeywell"')
            ->and($this->get('/sitemap.xml')->getContent())->not->toContain('/brands/honeywell<')->toContain('/brands/zebra<');

        $fixture['printers']->update(['is_active' => false]);
        $fixture['scanners']->update(['is_active' => false]);

        expect($this->get('/en/brands')->getContent())->not->toContain('data-brand-card="zebra"')
            ->and($this->get('/sitemap.xml')->getContent())->not->toContain('/brands/zebra<')->not->toContain('/en/brands<');
    });

    it('uses the newest visible product change as the brand lastmod', function () {
        $fixture = brandsFixture();
        $fixture['zebra']->forceFill(['updated_at' => now()->subYear()])->saveQuietly();
        [$first, $second] = Product::query()->where('brand_id', $fixture['zebra']->id)->where('is_active', true)->get()->all();
        $first->forceFill(['updated_at' => '2026-10-20 10:00:00'])->saveQuietly();
        $second->forceFill(['updated_at' => '2026-10-01 10:00:00'])->saveQuietly();
        Sitemap::flush();

        $xml = $this->get('/sitemap.xml')->getContent();

        expect($xml)->toMatch('#<loc>'.preg_quote(url('/en/brands/zebra'), '#').'</loc><lastmod>2026-10-20T10:00:00#');
    });

    it('flushes the sitemap and llms.txt when a brand changes', function () {
        $fixture = brandsFixture();
        $this->get('/sitemap.xml');
        $this->get('/llms.txt');

        $fixture['zebra']->update(['is_active' => false]);

        expect(Cache::has(Sitemap::CACHE_KEY))->toBeFalse()
            ->and(Cache::has(LlmsTxt::CACHE_KEY))->toBeFalse()
            ->and($this->get('/sitemap.xml')->getContent())->not->toContain('/brands/zebra<');
    });
});
