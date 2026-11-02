<?php

use App\Actions\Videos\UpdateVideo;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\Video;
use App\Support\Content\VideoThumbnails;
use App\Support\Site\Sitemap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Cache::flush();
    openSearchIndexing();
});

function sitemapXml(): SimpleXMLElement
{
    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();
    libxml_use_internal_errors(true);
    $document = simplexml_load_string($xml);
    expect($document)->not->toBeFalse();

    return $document;
}

function videoNodes(SimpleXMLElement $document): array
{
    $document->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $document->registerXPathNamespace('v', 'http://www.google.com/schemas/sitemap-video/1.1');

    return $document->xpath('//s:url/v:video') ?: [];
}

it('adds video entries for visible videos with a cached thumbnail, per locale', function () {
    $product = Product::factory()->create(['slug' => 'zd421']);
    VideoThumbnails::store('abcdefghijk', (function () {
        $image = imagecreatetruecolor(640, 360);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    })());
    Video::factory()->owner($product)->withThumbnail()->create([
        'youtube_id' => 'abcdefghijk',
        'title' => ['uz' => 'Sozlash', 'en' => 'Setup & <config>'],
        'published_on' => '2024-05-01',
    ]);
    Video::factory()->owner($product)->create(['youtube_id' => 'nothumb0000']);
    Video::factory()->owner($product)->withThumbnail()->inactive()->create(['youtube_id' => 'inactive000']);
    Video::factory()->owner($product)->withThumbnail()->unavailable()->create(['youtube_id' => 'unavail0000']);

    $document = sitemapXml();
    $nodes = videoNodes($document);

    expect($nodes)->toHaveCount(3);

    $english = collect($nodes)->first(fn ($node) => (string) $node->children('video', true)->title === 'Setup &');
    $children = $english->children('video', true);

    expect((string) $children->player_loc)->toBe('https://www.youtube.com/embed/abcdefghijk')
        ->and((string) $children->publication_date)->toBe('2024-05-01')
        ->and((string) $children->thumbnail_loc)->toContain('/storage/videos/abcdefghijk-')
        ->and((string) $children->description)->toBe('Setup &');
});

it('writes videos only for owners whose URL is in the sitemap', function () {
    $hidden = Category::factory()->create(['is_active' => false]);
    $unlisted = Brand::factory()->create();
    $draft = NewsPost::factory()->draft()->create();
    $inactiveProduct = Product::factory()->create(['is_active' => false]);
    foreach ([$hidden, $unlisted, $draft, $inactiveProduct] as $owner) {
        Video::factory()->owner($owner)->withThumbnail()->create();
    }
    $post = NewsPost::factory()->create();
    Video::factory()->owner($post)->withThumbnail()->create();

    $nodes = videoNodes(sitemapXml());

    // The published post exists in 3 locales.
    expect($nodes)->toHaveCount(3);
});

it('keeps the URL entries unchanged by videos', function () {
    $product = Product::factory()->create();
    $without = (new Sitemap)->generate()['urls'];

    Video::factory()->owner($product)->withThumbnail()->create();
    $with = (new Sitemap)->generate();

    expect($with['urls'])->toBe($without)
        ->and($with['counts']['products'])->toBe(3);
});

it('strips control characters and cuts multibyte titles safely so the XML stays valid', function () {
    $product = Product::factory()->create();
    Video::factory()->owner($product)->withThumbnail()->create([
        'youtube_id' => 'abcdefghijk',
        'source_title' => "Bad\x01title ".str_repeat('ўқ', 80),
        'fetch_status' => 'ok',
    ]);

    $nodes = videoNodes(sitemapXml());
    $title = (string) $nodes[0]->children('video', true)->title;

    expect($title)->toStartWith('Bad title')
        ->and(mb_strlen($title))->toBeLessThanOrEqual(100)
        ->and(mb_check_encoding($title, 'UTF-8'))->toBeTrue();
});

it('flushes the sitemap when a draft video is activated', function () {
    $product = Product::factory()->create();
    $video = Video::factory()->owner($product)->withThumbnail()->inactive()->create();

    expect(videoNodes(sitemapXml()))->toHaveCount(0);

    app(UpdateVideo::class)->handle($video, ['is_active' => true]);

    expect(videoNodes(sitemapXml()))->toHaveCount(3);
});
