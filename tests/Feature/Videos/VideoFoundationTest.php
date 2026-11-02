<?php

use App\Enums\VideoFetchStatus;
use App\Jobs\FetchVideoDetails;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\Video;
use App\Support\Content\VideoThumbnails;
use App\Support\Content\YoutubeVideo;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Http::preventStrayRequests();
});

/**
 * @return array<string, string> a real JPEG of the given size
 */
function jpegBytes(int $width = 1280, int $height = 720, string $kind = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, (int) ($height / 2), imagecolorallocate($image, 200, 30, 30));
    ob_start();
    match ($kind) {
        'png' => imagepng($image),
        default => imagejpeg($image),
    };

    return (string) ob_get_clean();
}

function oembed(string $title = 'Zebra ZD421 setup'): array
{
    return ['title' => $title, 'author_name' => 'Poson', 'type' => 'video'];
}

it('parses accepted YouTube link forms', function (string $input) {
    expect(YoutubeVideo::parse($input))->toBe('dQw4w9WgXcQ');
})->with([
    'bare id' => 'dQw4w9WgXcQ',
    'bare id padded' => "  dQw4w9WgXcQ\n",
    'watch' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'watch extras' => 'https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=10s#x',
    'no scheme' => 'youtube.com/watch?v=dQw4w9WgXcQ',
    'mobile' => 'http://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'music' => 'https://music.youtube.com/watch?v=dQw4w9WgXcQ',
    'upper host' => 'https://WWW.YouTube.COM/watch?v=dQw4w9WgXcQ',
    'short' => 'https://youtu.be/dQw4w9WgXcQ?si=abc',
    'shorts' => 'https://www.youtube.com/shorts/dQw4w9WgXcQ/',
    'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'nocookie' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
    'live' => 'https://www.youtube.com/live/dQw4w9WgXcQ',
    'v' => 'https://www.youtube.com/v/dQw4w9WgXcQ',
]);

it('rejects everything that is not one YouTube video', function (mixed $input) {
    expect(YoutubeVideo::parse($input))->toBeNull();
})->with([
    'other host' => 'https://evil.com/watch?v=dQw4w9WgXcQ',
    'suffix host' => 'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ',
    'prefix host' => 'https://evilyoutube.com/watch?v=dQw4w9WgXcQ',
    'userinfo' => 'https://user@www.youtube.com/watch?v=dQw4w9WgXcQ',
    'userinfo trick' => 'https://www.youtube.com@evil.com/watch?v=dQw4w9WgXcQ',
    'port' => 'https://www.youtube.com:8080/watch?v=dQw4w9WgXcQ',
    'javascript' => 'javascript:alert(1)',
    'ftp' => 'ftp://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'playlist' => 'https://www.youtube.com/playlist?list=PLabcdefghijk',
    'channel' => 'https://www.youtube.com/channel/UCabcdefghijklmnopqrstuv',
    'handle' => 'https://www.youtube.com/@poson',
    'watch no v' => 'https://www.youtube.com/watch',
    '10 chars' => 'dQw4w9WgXc',
    '12 chars' => 'dQw4w9WgXcQQ',
    'bad chars' => 'dQw4w9WgX"<',
    'quote in url' => 'https://youtu.be/dQw4w9WgX"<',
    'trailing dot host' => 'https://www.youtube.com./watch?v=dQw4w9WgXcQ',
    'backslash' => 'https://www.youtube.com\\@evil.com/watch?v=dQw4w9WgXcQ',
    'whitespace inside' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ evil',
    'encoded host' => 'https://www.youtube%2ecom/watch?v=dQw4w9WgXcQ',
    'non ascii host' => 'https://www.youtubе.com/watch?v=dQw4w9WgXcQ',
    'scheme relative' => '//www.youtube.com/watch?v=dQw4w9WgXcQ',
    'encoded newline v' => 'https://www.youtube.com/watch?v=dQw4w9WgXc%0a',
    'encoded newline id' => 'https://youtu.be/dQw4w9WgXc%0a',
    'array v' => 'https://www.youtube.com/watch?v[]=dQw4w9WgXcQ',
    'too long' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&x='.str_repeat('a', 500),
    'empty' => '',
    'array' => [['dQw4w9WgXcQ']],
    'null' => null,
]);

it('re-checks the id before building any URL', function () {
    expect(fn () => YoutubeVideo::embedUrl("dQw4w9WgXcQ\n"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => YoutubeVideo::watchUrl('bad'))->toThrow(InvalidArgumentException::class)
        ->and(YoutubeVideo::embedUrl('dQw4w9WgXcQ'))->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0');
});

it('keeps youtube ids case sensitive in the unique index', function () {
    $product = Product::factory()->create();

    Video::factory()->owner($product)->create(['youtube_id' => 'abcdefghijk']);
    Video::factory()->owner($product)->create(['youtube_id' => 'ABCDEFGHIJK']);

    expect(Video::query()->count())->toBe(2)
        ->and(fn () => Video::factory()->owner($product)->create(['youtube_id' => 'abcdefghijk']))->toThrow(QueryException::class);
});

it('names thumbnails with a hash so ids differing in case never share a file', function () {
    expect(VideoThumbnails::path('abcdefghijk'))->not->toBe(VideoThumbnails::path('ABCDEFGHIJK'))
        ->and(strtolower(VideoThumbnails::path('abcdefghijk')))->not->toBe(strtolower(VideoThumbnails::path('ABCDEFGHIJK')))
        ->and(VideoThumbnails::path('abcdefghijk'))->toMatch('#\Avideos/abcdefghijk-[0-9a-f]{8}\.jpg\z#');
});

it('crops, scales and re-encodes thumbnails and rejects unsafe images', function () {
    expect(VideoThumbnails::store('abcdefghijk', jpegBytes(480, 360)))->toBeTrue();

    $info = getimagesizefromstring(Storage::disk('public')->get(VideoThumbnails::path('abcdefghijk')));
    expect($info[2])->toBe(IMAGETYPE_JPEG)
        ->and($info[0])->toBe(480)
        ->and($info[1])->toBe(270);

    expect(VideoThumbnails::store('bbbbbbbbbbb', jpegBytes(1920, 1080)))->toBeTrue()
        ->and(getimagesizefromstring(Storage::disk('public')->get(VideoThumbnails::path('bbbbbbbbbbb')))[0])->toBe(1280);

    expect(VideoThumbnails::store('cccccccccc1', jpegBytes(2000, 1100)))->toBeFalse()
        ->and(VideoThumbnails::store('cccccccccc2', jpegBytes(640, 360, 'png')))->toBeFalse()
        ->and(VideoThumbnails::store('cccccccccc3', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'))->toBeFalse()
        ->and(VideoThumbnails::store('cccccccccc4', '<html>x</html>'))->toBeFalse()
        ->and(VideoThumbnails::store('cccccccccc5', substr(jpegBytes(640, 360), 0, 300)))->toBeFalse()
        ->and(Storage::disk('public')->allFiles('videos'))->toHaveCount(2);
});

it('fetches title, channel and thumbnail only from the two fixed hosts', function () {
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(oembed("  Zebra <b>ZD421</b>\x01 setup  ")),
        'i.ytimg.com/vi/abcdefghijk/maxresdefault.jpg' => Http::response(jpegBytes(1280, 720), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();

    $video->refresh();
    expect($video->fetch_status)->toBe(VideoFetchStatus::Ok)
        ->and($video->source_title)->toBe('Zebra ZD421 setup')
        ->and($video->source_author)->toBe('Poson')
        ->and($video->source_title_safe)->toBeTrue()
        ->and($video->thumbnail_at)->not->toBeNull()
        ->and(VideoThumbnails::exists('abcdefghijk'))->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.youtube.com/oembed?') && $request['url'] === 'https://www.youtube.com/watch?v=abcdefghijk');
    Http::assertSent(fn ($request) => $request->url() === 'https://i.ytimg.com/vi/abcdefghijk/maxresdefault.jpg');
});

it('falls back to hqdefault and survives a non-image thumbnail', function () {
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(oembed()),
        'i.ytimg.com/vi/abcdefghijk/maxresdefault.jpg' => Http::response('<html>', 404),
        'i.ytimg.com/vi/abcdefghijk/hqdefault.jpg' => Http::response(jpegBytes(480, 360)),
    ]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();

    expect($video->refresh()->thumbnail_at)->not->toBeNull();
});

it('survives a non-image thumbnail', function () {
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(oembed()),
        'i.ytimg.com/*' => Http::response('<html>not an image</html>'),
    ]);
    $other = Video::factory()->create(['youtube_id' => 'zzzzzzzzzzz']);

    (new FetchVideoDetails($other->id, 'zzzzzzzzzzz'))->handle();

    expect($other->refresh()->thumbnail_at)->toBeNull()
        ->and($other->fetch_status)->toBe(VideoFetchStatus::Ok);
});

it('marks the video unavailable on 400/401/403/404 and fetches no thumbnail', function (int $status) {
    Http::fake(['www.youtube.com/oembed*' => Http::response('', $status)]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();

    expect($video->refresh()->fetch_status)->toBe(VideoFetchStatus::Unavailable);
    Http::assertSentCount(1);
})->with([400, 401, 403, 404]);

it('throws on 429, 5xx, redirects and oversized bodies so the queue retries', function (mixed $response) {
    Http::fake(['www.youtube.com/oembed*' => $response]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    expect(fn () => (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle())->toThrow(RuntimeException::class);
    expect($video->refresh()->fetch_status)->toBe(VideoFetchStatus::Pending);
})->with([
    '429' => fn () => Http::response('', 429),
    '500' => fn () => Http::response('', 500),
    '503' => fn () => Http::response('', 503),
    '302' => fn () => Http::response('', 302, ['Location' => 'https://evil.com/']),
    'over 64 KB' => fn () => Http::response(json_encode(['title' => str_repeat('a', 70000)])),
    'bad json' => fn () => Http::response('not json'),
]);

it('marks rows failed when the retries are exhausted and ignores a deleted row', function () {
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->failed(new RuntimeException('x'));
    expect($video->refresh()->fetch_status)->toBe(VideoFetchStatus::Failed);

    $video->delete();
    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();
    Http::assertNothingSent();
});

it('is unique per youtube id with a 30 second timeout and 4 tries', function () {
    $job = new FetchVideoDetails(1, 'abcdefghijk');

    expect($job->uniqueId())->toBe('abcdefghijk')
        ->and($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(4)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and(fn () => new FetchVideoDetails(1, "abcdefghijk\n"))->toThrow(InvalidArgumentException::class);
});

it('flags a brand-unsafe oEmbed title and marks the video without using it', function () {
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(oembed('Arzon printerlar chegirma bilan')),
        'i.ytimg.com/*' => Http::response('', 404),
    ]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();

    $video->refresh();
    expect($video->source_title_safe)->toBeFalse()
        ->and($video->displayTitle('uz'))->toBeNull();
});

it('touches each distinct active owner once when the id is on several owners', function () {
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(oembed()),
        'i.ytimg.com/*' => Http::response('', 404),
    ]);
    $a = Product::factory()->create();
    $b = Category::factory()->create();
    $c = Brand::factory()->create();
    $old = now()->subDay()->startOfSecond();
    foreach ([$a, $b, $c] as $owner) {
        $owner->forceFill(['updated_at' => $old])->saveQuietly();
    }
    Video::factory()->owner($a)->create(['youtube_id' => 'abcdefghijk']);
    Video::factory()->owner($b)->create(['youtube_id' => 'abcdefghijk']);
    Video::factory()->owner($c)->inactive()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails(1, 'abcdefghijk'))->handle();

    expect($a->fresh()->updated_at->gt($old))->toBeTrue()
        ->and($b->fresh()->updated_at->gt($old))->toBeTrue()
        ->and($c->fresh()->updated_at->equalTo($old))->toBeTrue();
});

it('removes videos and orphan thumbnails with the owner but keeps them for a soft-deleted product', function () {
    $category = Category::factory()->create();
    $post = NewsPost::factory()->create();
    $product = Product::factory()->create();
    foreach ([['abcdefghijk', $category], ['bbbbbbbbbbb', $post], ['ccccccccccc', $product]] as [$id, $owner]) {
        Video::factory()->owner($owner)->withThumbnail()->create(['youtube_id' => $id]);
        VideoThumbnails::store($id, jpegBytes(640, 360));
    }
    // The same id on a second owner keeps the file.
    Video::factory()->owner(Product::factory()->create())->create(['youtube_id' => 'abcdefghijk']);

    $category->delete();
    $post->delete();
    $product->delete();

    expect(Video::query()->ownedBy($category)->count())->toBe(0)
        ->and(Video::query()->ownedBy($post)->count())->toBe(0)
        ->and(Video::query()->ownedBy($product)->count())->toBe(1)
        ->and(VideoThumbnails::exists('abcdefghijk'))->toBeTrue()
        ->and(VideoThumbnails::exists('bbbbbbbbbbb'))->toBeFalse()
        ->and(VideoThumbnails::exists('ccccccccccc'))->toBeTrue();

    $product->forceDelete();

    expect(Video::query()->ownedBy($product)->count())->toBe(0)
        ->and(VideoThumbnails::exists('ccccccccccc'))->toBeFalse();
});

it('stores the short video morph alias', function () {
    $video = Video::factory()->create();

    expect($video->getMorphClass())->toBe('video')
        ->and($video->videoable_type)->toBe('product')
        ->and($video->videoable)->toBeInstanceOf(Product::class);
});
