<?php

use App\Enums\VideoFetchStatus;
use App\Jobs\FetchVideoDetails;
use App\Models\Product;
use App\Models\Video;
use App\Support\Content\VideoThumbnails;
use App\Support\Content\YoutubeVideo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Http::preventStrayRequests();
});

function hardeningJpeg(int $width = 1280, int $height = 720): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

it('rejects ids with a trailing newline, IDN hosts and over-long input', function () {
    expect(YoutubeVideo::isId("dQw4w9WgXcQ\n"))->toBeFalse()
        ->and(YoutubeVideo::isId('dQw4w9WgXcQ'))->toBeTrue()
        ->and(YoutubeVideo::isId(null))->toBeFalse()
        ->and(fn () => YoutubeVideo::assertId("dQw4w9WgXcQ\n"))->toThrow(InvalidArgumentException::class)
        ->and(YoutubeVideo::parse('https://xn--youtub-8ve.com/watch?v=dQw4w9WgXcQ'))->toBeNull()
        ->and(YoutubeVideo::parse('https://www.youtube.com/watch?v=dQw4w9WgXcQ%0a'))->toBeNull()
        ->and(YoutubeVideo::parse("https://www.youtube.com/watch?v=dQw4w9WgXcQ\n"))->toBe('dQw4w9WgXcQ');
});

it('rejects an oEmbed response whose Content-Length is over 64 KB', function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response('{"title":"x"}', 200, ['Content-Length' => '70000'])]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    expect(fn () => (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle())->toThrow(RuntimeException::class);
    expect($video->refresh()->fetch_status)->toBe(VideoFetchStatus::Pending)
        ->and($video->source_title)->toBeNull();
});

it('does not follow thumbnail redirects and rejects oversized thumbnails', function (mixed $response) {
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(['title' => 'Setup', 'author_name' => 'Poson']),
        'i.ytimg.com/*' => $response,
    ]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();

    expect($video->refresh()->thumbnail_at)->toBeNull()
        ->and($video->fetch_status)->toBe(VideoFetchStatus::Ok)
        ->and(VideoThumbnails::exists('abcdefghijk'))->toBeFalse();
    Http::assertNotSent(fn ($request) => ! in_array($request->toPsrRequest()->getUri()->getHost(), ['www.youtube.com', 'i.ytimg.com'], true));
})->with([
    '302 to another host' => fn () => Http::response('', 302, ['Location' => 'https://evil.com/x.jpg']),
    'body over 2 MB' => fn () => Http::response(str_repeat('a', 2097152 + 10)),
    'Content-Length over 2 MB' => fn () => Http::response(hardeningJpeg(), 200, ['Content-Length' => '3000000']),
]);

it('never logs the title, link or body', function () {
    Log::spy();
    Http::fake([
        'www.youtube.com/oembed*' => Http::response(['title' => 'SecretTitleXYZ', 'author_name' => 'ChannelXYZ']),
        'i.ytimg.com/*' => Http::response(hardeningJpeg()),
    ]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();
    (new FetchVideoDetails($video->id, 'abcdefghijk'))->failed(new RuntimeException('https://www.youtube.com/watch?v=abcdefghijk SecretTitleXYZ'));

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ! str_contains(json_encode([$message, $context]), 'SecretTitleXYZ')
        && ! str_contains(json_encode([$message, $context]), 'abcdefghijk')
        && ! str_contains(json_encode([$message, $context]), 'ChannelXYZ'))->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => ! str_contains(json_encode([$message, $context]), 'SecretTitleXYZ')
        && ! str_contains(json_encode([$message, $context]), 'youtube.com'))->once();
});

it('scrubs invalid bytes and tags from a fetched title', function () {
    Http::fake([
        'www.youtube.com/oembed*' => Http::response('{"title":"Printer \u0001 <script>x</script> ","author_name":"Poson"}'),
        'i.ytimg.com/*' => Http::response('', 404),
    ]);
    $video = Video::factory()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();

    expect($video->refresh()->source_title)->not->toContain('<')
        ->and(mb_check_encoding((string) $video->source_title, 'UTF-8'))->toBeTrue()
        ->and($video->source_title)->not->toMatch('/[\x00-\x08]/');
});

it('does not touch an owner when nothing public changed', function () {
    Http::fake(['www.youtube.com/oembed*' => Http::response('', 404)]);
    $product = Product::factory()->create();
    $old = now()->subDay()->startOfSecond();
    $product->forceFill(['updated_at' => $old])->saveQuietly();
    $video = Video::factory()->owner($product)->unavailable()->create(['youtube_id' => 'abcdefghijk']);

    (new FetchVideoDetails($video->id, 'abcdefghijk'))->handle();

    expect($product->fresh()->updated_at->equalTo($old))->toBeTrue();
});
