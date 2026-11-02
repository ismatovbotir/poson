<?php

use App\Actions\Mcp\CreateMcpVideoDraft;
use App\Jobs\FetchVideoDetails;
use App\Livewire\Admin\Videos\VideoList;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\Video;
use App\Support\Content\VideoThumbnails;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
});

it('keeps multibyte quotes in untitled-video captions and JSON-LD', function () {
    $product = Product::factory()->create(['slug' => 'zd421', 'name' => ['uz' => '“Zebra” ZD421']]);
    $post = NewsPost::factory()->create(['slug' => 'poson-news', 'title' => ['uz' => '“Poson” yangiliklari']]);

    foreach ([$product, $post] as $index => $owner) {
        Video::factory()->owner($owner)->withThumbnail()->create(['youtube_id' => 'abcdefghij'.$index, 'published_on' => '2026-05-01']);
    }

    foreach (['/uz/product/zd421' => '“Zebra” ZD421', '/uz/news/poson-news' => '“Poson” yangiliklari'] as $url => $name) {
        $html = $this->get($url)->assertOk()->getContent();

        expect(mb_check_encoding($html, 'UTF-8'))->toBeTrue()
            ->and($html)->toContain($name.' — ')
            ->and(preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m))->toBeGreaterThan(0);

        $video = collect($m[1])->map(fn (string $json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR))->firstWhere('@type', 'VideoObject');

        expect($video)->not->toBeNull()->and($video['name'])->toStartWith($name);
    }
});

it('tolerates a missing active image in the gallery script and hides the label diagram with the video', function () {
    $product = Product::factory()->create(['slug' => 'zd421']);
    Video::factory()->owner($product)->create(['youtube_id' => 'abcdefghijk']);

    $html = $this->get('/en/product/zd421')->assertOk()->getContent();

    expect($html)->not->toContain('images[active].');
});

it('renders the x-show attribute on the label diagram svg', function () {
    expect(file_get_contents(resource_path('views/livewire/site/catalog/show.blade.php')))
        ->toContain('<x-catalog.label-diagram')->toContain('x-show="active !== videoIndex"')
        ->and(file_get_contents(resource_path('views/components/catalog/label-diagram.blade.php')))->toContain('<svg {{ $attributes }}');
});

it('uses a unique-until-processing job keyed by youtube id', function () {
    $job = new FetchVideoDetails(1, 'abcdefghijk');

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('abcdefghijk');
});

it('rejects a JPEG truncated mid-scan and accepts the complete one', function () {
    $image = imagecreatetruecolor(1280, 720);
    for ($i = 0; $i < 4000; $i++) {
        imagesetpixel($image, random_int(0, 1279), random_int(0, 719), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    expect(VideoThumbnails::store('abcdefghijk', substr($jpeg, 0, (int) (strlen($jpeg) * 0.6))))->toBeFalse()
        ->and(VideoThumbnails::exists('abcdefghijk'))->toBeFalse()
        ->and(VideoThumbnails::store('abcdefghijk', $jpeg))->toBeTrue();
});

it('answers 404 to tampered non-numeric video ids in the admin list', function (string $method, array $args) {
    Queue::fake();
    $product = Product::factory()->create();

    Livewire::actingAs(staffUser())->test(VideoList::class, ['product' => $product])
        ->call($method, ...$args)
        ->assertNotFound();
})->with([
    'edit' => ['edit', ['abc']],
    'toggle' => ['toggle', [['x']]],
    'move' => ['move', ['abc', 1]],
    'refresh' => ['refresh', ['1; drop']],
    'delete' => ['delete', [null]],
]);

it('leaves the product and the video row untouched when an existing video is re-attached by MCP', function () {
    Queue::fake();
    $admin = staffUser();
    $product = Product::factory()->create();
    $video = Video::factory()->owner($product)->create(['youtube_id' => 'abcdefghijk']);
    $this->travel(5)->minutes();

    expect(fn () => app(CreateMcpVideoDraft::class)->handle($admin, ['owner' => 'product:'.$product->id, 'url' => 'https://youtu.be/abcdefghijk']))
        ->toThrow(ValidationException::class);

    expect(Product::query()->find($product->id)->updated_at->equalTo($product->updated_at))->toBeTrue()
        ->and(Video::query()->where('youtube_id', 'abcdefghijk')->count())->toBe(1)
        ->and(Video::query()->find($video->id)->getAttributes())->toBe($video->fresh()->getAttributes());
    Queue::assertNothingPushed();
});
