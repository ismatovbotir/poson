<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\Video;
use App\Support\Content\VideoThumbnails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

function videoPage(string $kind, array $videos = [['abcdefghijk', []]]): array
{
    $owner = match ($kind) {
        'product' => Product::factory()->create(['slug' => 'zd421']),
        'category' => Category::factory()->create(['slug' => 'printers']),
        'brand' => Brand::factory()->create(['slug' => 'zebra']),
        'news' => NewsPost::factory()->create(['slug' => 'setup-news']),
        'article' => NewsPost::factory()->article()->create(['slug' => 'setup-guide']),
    };

    foreach ($videos as [$id, $state]) {
        Video::factory()->owner($owner)->create(['youtube_id' => $id, ...$state]);
    }

    $url = match ($kind) {
        'product' => '/en/product/zd421',
        'category' => '/en/catalog/printers',
        'brand' => '/en/brands/zebra',
        'news' => '/en/news/setup-news',
        'article' => '/en/articles/setup-guide',
    };

    return [$owner, $url];
}

it('shows a click-to-load facade with no Google request before the click', function (string $kind) {
    [, $url] = videoPage($kind, [['abcdefghijk', ['source_title' => 'Zebra setup', 'fetch_status' => 'ok']]]);

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('data-video-facade')
        ->toContain('Zebra setup')
        ->toContain('data-video-play')
        ->toContain(__('site.videos.notice', locale: 'en'))
        ->toContain('https://www.youtube.com/watch?v=abcdefghijk')
        ->not->toMatch('#\ssrc="[^"]*(youtube|ytimg|google)#i')
        ->not->toContain('ytimg')
        ->not->toContain('<iframe src=')
        ->and(preg_match_all('#<iframe[^>]*>#', $html, $iframes))->toBeGreaterThan(0);

    foreach ($iframes[0] as $iframe) {
        expect($iframe)->toContain('x-bind:src="src"')
            ->toContain('allow="autoplay; encrypted-media; picture-in-picture; web-share"')
            ->toContain('referrerpolicy="strict-origin-when-cross-origin"')
            ->toContain('allowfullscreen')
            ->not->toContain('sandbox')
            ->not->toContain('accelerometer');
    }

    // The only embed URL in the page is the nocookie one, in x-data.
    preg_match_all('#youtube-nocookie\.com[^"&]*#', $html, $nocookie);
    expect($nocookie[0])->not->toBeEmpty()
        ->and($html)->not->toContain('www.youtube.com\/embed')
        ->and($html)->toContain('youtube-nocookie.com\/embed\/abcdefghijk?autoplay=1');
})->with(['product', 'category', 'brand', 'news', 'article']);

it('does not claim that no cookies are used (YT-S15)', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(mb_strtolower(__('site.videos.notice', locale: $locale)))->not->toContain('cookie')->not->toContain('kuki');
    }
});

it('hides inactive and unavailable videos and shows nothing without videos', function () {
    [, $url] = videoPage('product', [
        ['aaaaaaaaaaa', ['is_active' => false]],
        ['bbbbbbbbbbb', ['fetch_status' => 'unavailable']],
    ]);

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->not->toContain('data-video-facade')->not->toContain('data-videos')->not->toContain('aaaaaaaaaaa')->not->toContain('bbbbbbbbbbb');
});

it('uses the local thumbnail and never the ytimg host', function () {
    VideoThumbnails::store('abcdefghijk', (function () {
        $image = imagecreatetruecolor(640, 360);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    })());
    [, $url] = videoPage('brand', [['abcdefghijk', ['thumbnail_at' => now()]]]);

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('/storage/videos/abcdefghijk-')->not->toContain('ytimg');
});

it('escapes titles and falls back for unsafe oEmbed titles', function () {
    [, $url] = videoPage('product', [
        ['abcdefghijk', ['source_title' => '<img src=x onerror=alert(1)> "quoted"', 'fetch_status' => 'ok']],
        ['bbbbbbbbbbb', ['source_title' => 'Arzon printerlar chegirma', 'source_title_safe' => true, 'fetch_status' => 'ok']],
    ]);

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->not->toContain('<img src=x')
        ->toContain('&lt;img src=x')
        ->not->toContain('Arzon printerlar')
        ->not->toContain('chegirma');
});

it('prefers the manual title of the locale and falls back to uz', function () {
    [, $url] = videoPage('product', [['abcdefghijk', ['title' => ['uz' => 'Uz sarlavha', 'en' => 'English title']]]]);

    $this->get($url)->assertSee('English title');
    $this->get('/ru/product/zd421')->assertSee('Uz sarlavha');
});

it('puts the first visible video last in the product gallery and all in the section', function () {
    [, $url] = videoPage('product', [['aaaaaaaaaaa', ['sort' => 1]], ['bbbbbbbbbbb', ['sort' => 2]]]);

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('data-gallery-video-thumb')
        ->and(substr_count($html, 'data-gallery-video>'))->toBe(1)
        ->and(substr_count($html, 'data-video-facade'))->toBe(3)
        ->and($html)->toContain('id="product-videos"')
        ->and(strpos($html, 'data-gallery-video'))->toBeLessThan(strpos($html, 'id="product-videos"'));
});

it('shows category and brand videos on the first page only', function (string $kind) {
    config(['site.per_page' => 1]);
    [$owner, $url] = videoPage($kind);
    $kind === 'category'
        ? Product::factory()->count(2)->inCategory($owner)->create()
        : Product::factory()->count(2)->create(['brand_id' => $owner->id]);

    expect($this->get($url)->assertOk()->getContent())->toContain('data-videos')
        ->and($this->get($url.'?page=2')->assertOk()->getContent())->not->toContain('data-videos')->not->toContain('VideoObject');
})->with(['category', 'brand']);

it('runs one videos query per page', function (string $kind) {
    [, $url] = videoPage($kind, [['aaaaaaaaaaa', []], ['bbbbbbbbbbb', []], ['ccccccccccc', []]]);
    $this->get($url)->assertOk();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from `videos`') || str_contains($query->sql, 'from "videos"')) {
            $queries[] = $query->sql;
        }
    });
    $this->get($url)->assertOk();

    expect($queries)->toHaveCount(1);
})->with(['product', 'category', 'brand', 'news', 'article']);

it('prints VideoObject only with a date and a cached thumbnail, at most five', function () {
    $owner = Product::factory()->create(['slug' => 'zd421']);
    foreach (range(1, 7) as $i) {
        Video::factory()->owner($owner)->withThumbnail()->create([
            'youtube_id' => sprintf('video%06d', $i),
            'sort' => $i,
            'published_on' => '2024-05-0'.$i,
            'title' => ['uz' => 'Sarlavha </script><b> '.$i],
        ]);
    }
    Video::factory()->owner($owner)->withThumbnail()->create(['youtube_id' => 'nodate00000', 'sort' => 8]);
    Video::factory()->owner($owner)->create(['youtube_id' => 'nothumb0000', 'sort' => 9, 'published_on' => '2024-05-09']);

    $html = $this->get('/en/product/zd421')->assertOk()->getContent();
    $objects = array_values(array_filter(jsonLdBlocks($html), fn (array $block): bool => ($block['@type'] ?? null) === 'VideoObject'));

    expect($objects)->toHaveCount(5)
        ->and($objects[0]['uploadDate'])->toBe('2024-05-01T00:00:00+05:00')
        ->and($objects[0]['embedUrl'])->toBe('https://www.youtube.com/embed/video000001')
        ->and($objects[0]['contentUrl'])->toBe('https://www.youtube.com/watch?v=video000001')
        ->and($objects[0]['thumbnailUrl'][0])->toStartWith('http')
        ->and($objects[0]['name'])->toContain('</script>')
        ->and($html)->not->toContain('</script><b>');
});

it('renders hostile stored titles safely with valid JSON-LD', function () {
    $owner = Product::factory()->create(['slug' => 'zd421']);
    Video::factory()->owner($owner)->withThumbnail()->create([
        'youtube_id' => 'abcdefghijk',
        'title' => null,
        'source_title' => "Bad\x01 </script><script>alert(1)</script> ".str_repeat('ў', 50),
        'source_title_safe' => true,
        'published_on' => '2024-05-01',
    ]);

    $html = $this->get('/en/product/zd421')->assertOk()->getContent();
    $objects = array_values(array_filter(jsonLdBlocks($html), fn (array $block): bool => ($block['@type'] ?? null) === 'VideoObject'));

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($objects)->toHaveCount(1);
});
