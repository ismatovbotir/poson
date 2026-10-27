<?php

use App\Actions\Banners\DeleteBanner;
use App\Actions\Banners\MoveBanner;
use App\Actions\Banners\SaveBanner;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Support\Site\BannerDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * Forget the per-request copy (a new request), keeping the cache.
 */
function newBannerRequest(): void
{
    app()->forgetInstance('site.banners.memo');
}

/**
 * The <img> tags of the home slider, in order.
 *
 * @return list<string>
 */
function sliderImages(string $html): array
{
    preg_match('#<section[^>]*data-banner-slider.*?</section>#s', $html, $section);
    preg_match_all('#<img\b[^>]*>#s', $section[0] ?? '', $images);

    return $images[0];
}

describe('home slider', function () {
    it('shows live banners below the static hero in sort order, without autoplay', function () {
        Banner::factory()->withImages(mobile: true)->create(['sort' => 2, 'title' => ['uz' => 'Ikkinchi banner'], 'link' => 'https://zebra.com/uz']);
        Banner::factory()->withImages()->create(['sort' => 1, 'title' => ['uz' => 'Birinchi banner'], 'link' => '/uz/catalog']);

        $html = $this->get('/uz')->assertOk()->getContent();

        expect($html)
            ->toContain('id="hero-title"')
            ->toContain('aria-roledescription="carousel"')
            ->toContain('aria-roledescription="slide"')
            ->toContain(e(__('site.banners.slide', ['current' => 1, 'total' => 2], 'uz')).': Birinchi banner')
            ->toContain('data-banner-prev')
            ->toContain('data-banner-next');

        preg_match('#<section[^>]*data-banner-slider.*?</section>#s', $html, $slider);

        expect($slider[0])
            ->not->toContain('autoplay')
            ->not->toContain('setInterval')
            ->not->toContain('setTimeout');

        expect(strpos($html, 'id="hero-title"'))->toBeLessThan(strpos($html, 'data-banner-slider'))
            ->and(strpos($html, 'Birinchi banner'))->toBeLessThan(strpos($html, 'Ikkinchi banner'));

        $images = sliderImages($html);

        expect($images)->toHaveCount(2)
            ->and($images[0])->toContain('loading="eager"')->toContain('fetchpriority="high"')
            ->toContain('width="'.Banner::DESKTOP_WIDTH.'"')->toContain('height="'.Banner::DESKTOP_HEIGHT.'"')
            ->and($images[1])->toContain('loading="lazy"')->not->toContain('fetchpriority');

        // Only the second banner has a mobile image: one <source>, with its size.
        expect(substr_count($html, '<source media="(max-width: 639px)"'))->toBe(1)
            ->and($html)->toContain('width="'.Banner::MOBILE_WIDTH.'" height="'.Banner::MOBILE_HEIGHT.'"');

        // The internal link stays as entered; the external one opens with noopener.
        expect($html)->toContain('href="/uz/catalog"')
            ->toMatch('#href="https://zebra.com/uz"\s+target="_blank" rel="noopener"#');

        expectNoBannedWording($html);
    });

    it('hides the slider entirely without live banners', function () {
        Banner::factory()->withImages()->inactive()->create();
        Banner::factory()->withImages()->scheduled()->create();
        Banner::factory()->withImages()->expired()->create();
        Banner::factory()->withImages()->category()->create();

        $this->get('/uz')->assertOk()->assertDontSee('data-banner-slider', false)->assertSee('id="hero-title"', false);
    });

    it('has no controls for a single banner', function () {
        Banner::factory()->withImages()->create();

        $this->get('/ru')->assertOk()
            ->assertSee('data-banner-slider', false)
            ->assertDontSee('data-banner-next', false)
            ->assertDontSee('data-banner-dot', false);
    });

    it('shows the texts in the locale, falling back to uz', function () {
        Banner::factory()->withImages()->create([
            'title' => ['uz' => 'Servis markazi', 'ru' => 'Сервисный центр'],
            'text' => ['uz' => 'Taʼmirlash'],
            'button_label' => null,
        ]);

        $this->get('/ru')->assertSee('Сервисный центр')->assertSee('Taʼmirlash')->assertSee(__('site.banners.more', [], 'ru'));
        $this->get('/en')->assertSee('Servis markazi')->assertSee(__('site.banners.more', [], 'en'));
    });

    it('waits for the desktop image conversion', function () {
        Banner::factory()->create(['title' => ['uz' => 'Rasmsiz banner']]);

        $this->get('/uz')->assertDontSee('Rasmsiz banner');
    });
});

describe('date window and cache', function () {
    it('shows a banner only while active and within its dates', function () {
        Banner::factory()->withImages()->active()->create(['title' => ['uz' => 'Hozirgi']]);
        Banner::factory()->withImages()->inactive()->create(['title' => ['uz' => 'Oʻchirilgan']]);
        Banner::factory()->withImages()->scheduled()->create(['title' => ['uz' => 'Kelgusi']]);
        Banner::factory()->withImages()->expired()->create(['title' => ['uz' => 'Tugagan']]);

        app()->setLocale('uz');

        expect(array_map(fn ($slide) => $slide->title, BannerDirectory::home()))->toBe(['Hozirgi']);
    });

    it('shows a scheduled banner and hides an ended one at the boundary, and expires the cache there', function () {
        $this->freezeSecond();
        Banner::factory()->withImages()->scheduled(now()->addHours(2))->create(['title' => ['uz' => 'Kelgusi']]);
        Banner::factory()->withImages()->create(['title' => ['uz' => 'Tugaydigan'], 'ends_at' => now()->addHour()]);
        app()->setLocale('uz');

        expect(array_map(fn ($slide) => $slide->title, BannerDirectory::home()))->toBe(['Tugaydigan'])
            ->and(Cache::has(BannerDirectory::CACHE_KEY))->toBeTrue();

        $this->travel(1)->hours();
        newBannerRequest();

        expect(Cache::has(BannerDirectory::CACHE_KEY))->toBeFalse()
            ->and(BannerDirectory::home())->toBe([]);

        $this->travel(1)->hours();
        newBannerRequest();

        expect(Cache::has(BannerDirectory::CACHE_KEY))->toBeFalse()
            ->and(array_map(fn ($slide) => $slide->title, BannerDirectory::home()))->toBe(['Kelgusi']);
    });

    it('caches until the next boundary, at most a day', function () {
        $this->freezeSecond();
        $rows = [
            ['starts_at' => now()->subDay()->toAtomString(), 'ends_at' => now()->addHours(5)->toAtomString()],
            ['starts_at' => now()->addHours(3)->toAtomString(), 'ends_at' => null],
        ];

        expect(BannerDirectory::expiry($rows)->equalTo(now()->addHours(3)))->toBeTrue()
            ->and(BannerDirectory::expiry([])->equalTo(now()->addSeconds(BannerDirectory::MAX_TTL)))->toBeTrue();
    });

    it('serves warm reads without queries and flushes on save, move and delete', function () {
        $banner = Banner::factory()->withImages()->create(['title' => ['uz' => 'Eski sarlavha'], 'sort' => 1]);
        $other = Banner::factory()->withImages()->create(['title' => ['uz' => 'Boshqa'], 'sort' => 2]);
        app()->setLocale('uz');

        $cold = countQueries(fn () => BannerDirectory::home());
        newBannerRequest();
        $warm = countQueries(fn () => BannerDirectory::home());

        expect($cold)->toBeLessThanOrEqual(2)->and($warm)->toBe(0);

        app(SaveBanner::class)->handle($banner, [
            'placement' => 'home_hero', 'category_id' => null, 'title' => ['uz' => 'Yangi sarlavha'], 'text' => [], 'button_label' => [],
            'link' => null, 'starts_at' => null, 'ends_at' => null, 'is_active' => true,
        ]);

        expect(array_map(fn ($slide) => $slide->title, BannerDirectory::home()))->toBe(['Yangi sarlavha', 'Boshqa']);

        app(MoveBanner::class)->handle($other->fresh(), -1);

        expect(array_map(fn ($slide) => $slide->title, BannerDirectory::home()))->toBe(['Boshqa', 'Yangi sarlavha']);

        app(DeleteBanner::class)->handle($other->fresh());

        expect(array_map(fn ($slide) => $slide->title, BannerDirectory::home()))->toBe(['Yangi sarlavha']);
    });

    it('runs the same number of queries for one or many banners', function () {
        Banner::factory()->withImages()->create();
        app()->setLocale('uz');
        BannerDirectory::flush();
        $one = countQueries(fn () => BannerDirectory::home());

        Banner::factory()->count(4)->withImages(mobile: true)->create();
        BannerDirectory::flush();
        $many = countQueries(fn () => expect(BannerDirectory::home())->toHaveCount(5));

        expect($many)->toBe($one)->toBeLessThanOrEqual(2);
    });
});

describe('brand rule', function () {
    it('drops a banner whose texts break the brand rule (edited in the DB)', function () {
        $banner = Banner::factory()->withImages()->create(['title' => ['uz' => 'Toza banner', 'ru' => 'Чистый баннер', 'en' => 'Clean banner']]);
        Banner::factory()->withImages()->create(['title' => ['uz' => 'Ikkinchi', 'ru' => 'Второй', 'en' => 'Second']]);

        DB::table('banners')->where('id', $banner->id)->update(['text' => json_encode(['ru' => 'Скидки до 50%'], JSON_UNESCAPED_UNICODE)]);
        BannerDirectory::flush();

        $this->get('/ru')->assertOk()->assertDontSee('Чистый баннер')->assertSee('Второй');
        $this->get('/uz')->assertOk()->assertSee('Toza banner');
    });

    it('drops a banner with a banned alt text and ignores an invalid link', function () {
        $banner = Banner::factory()->withImages()->create(['title' => ['uz' => 'Alt banner']]);
        $media = $banner->bannerMedia();
        $media->setCustomProperty('alt', ['uz' => 'Eng arzon skaner'])->save();
        $linked = Banner::factory()->withImages()->create(['title' => ['uz' => 'Havolali']]);
        DB::table('banners')->where('id', $linked->id)->update(['link' => '//evil.example.com']);
        BannerDirectory::flush();

        $html = $this->get('/uz')->assertOk()->getContent();

        expect($html)->not->toContain('Alt banner')
            ->toContain('Havolali')
            ->not->toContain('evil.example.com');
    });
});

describe('category banner', function () {
    it('shows the first live banner of the category on page 1 only', function () {
        config(['site.per_page' => 1]);
        $category = Category::factory()->create(['slug' => 'scanners']);
        $other = Category::factory()->create(['slug' => 'printers']);
        Product::factory()->count(2)->create(['category_id' => $category->id]);
        Banner::factory()->withImages()->category($category)->create(['sort' => 2, 'title' => ['uz' => 'Ikkinchi kategoriya banneri']]);
        Banner::factory()->withImages()->category($category)->create(['sort' => 1, 'title' => ['uz' => 'Skaner banneri']]);
        Banner::factory()->withImages()->category($category)->expired()->create(['sort' => 0, 'title' => ['uz' => 'Tugagan banner']]);
        Banner::factory()->withImages()->create(['title' => ['uz' => 'Bosh sahifa banneri']]);

        $html = $this->get('/uz/catalog/scanners')->assertOk()->getContent();

        expect($html)->toContain('data-category-banner')
            ->toContain('Skaner banneri')
            ->not->toContain('Ikkinchi kategoriya banneri')
            ->not->toContain('Tugagan banner')
            ->not->toContain('Bosh sahifa banneri');

        expect(strpos($html, '<h1'))->toBeLessThan(strpos($html, 'data-category-banner'));

        $this->get('/uz/catalog/scanners?page=2')->assertOk()->assertDontSee('data-category-banner', false);
        $this->get('/uz/catalog/printers')->assertOk()->assertDontSee('data-category-banner', false);
        $this->get('/uz/catalog')->assertOk()->assertDontSee('data-category-banner', false);

        expect($other->exists)->toBeTrue();
    });
});
