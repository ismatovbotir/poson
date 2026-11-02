<?php

use App\Actions\Videos\AttachVideo;
use App\Actions\Videos\DeleteVideo;
use App\Actions\Videos\MoveVideo;
use App\Actions\Videos\RefreshVideoDetails;
use App\Actions\Videos\UpdateVideo;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\VideoFetchStatus;
use App\Jobs\FetchVideoDetails;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Models\Video;
use App\Policies\VideoPolicy;
use App\Support\Content\VideoThumbnails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

function touchedAt(Model $model, Carbon $old): bool
{
    return $model->fresh()->updated_at->gt($old);
}

function oldTimestamp(Model $model): Carbon
{
    static $days = 0;
    $old = now()->subDays(++$days)->startOfSecond();
    $model->forceFill(['updated_at' => $old])->saveQuietly();

    return $old;
}

it('attaches a video to each owner type, pending, ordered, and queues the fetch', function (Closure $make) {
    $owner = $make();

    $first = app(AttachVideo::class)->handle($owner, 'https://youtu.be/abcdefghijk');
    $second = app(AttachVideo::class)->handle($owner, 'ABCDEFGHIJK', ['title' => ['uz' => 'Sozlash', 'ru' => '']]);

    expect($first->fetch_status)->toBe(VideoFetchStatus::Pending)
        ->and($first->is_active)->toBeTrue()
        ->and($first->sort)->toBe(1)
        ->and($second->sort)->toBe(2)
        ->and($second->getTranslations('title'))->toBe(['uz' => 'Sozlash'])
        ->and($owner->videos()->pluck('youtube_id')->all())->toBe(['abcdefghijk', 'ABCDEFGHIJK']);

    Queue::assertPushed(FetchVideoDetails::class, 2);
})->with([
    'product' => fn () => Product::factory()->create(),
    'category' => fn () => Category::factory()->create(),
    'brand' => fn () => Brand::factory()->create(),
    'post' => fn () => NewsPost::factory()->create(),
]);

it('rejects invalid links without echoing them', function () {
    $product = Product::factory()->create();
    $evil = 'https://evil.example/<script>alert(1)</script>';

    try {
        app(AttachVideo::class)->handle($product, $evil);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect(json_encode($e->errors()))->not->toContain('evil')->not->toContain('script')
            ->and($e->errors())->toHaveKey('url');
    }

    expect(Video::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses duplicates per owner but allows the same video on another owner', function () {
    $product = Product::factory()->create();

    app(AttachVideo::class)->handle($product, 'abcdefghijk');

    expect(fn () => app(AttachVideo::class)->handle($product, 'https://youtu.be/abcdefghijk'))->toThrow(ValidationException::class)
        ->and(app(AttachVideo::class)->handle(Product::factory()->create(), 'abcdefghijk'))->toBeInstanceOf(Video::class);
});

it('caps videos per owner at 20', function () {
    $product = Product::factory()->create();
    foreach (range(1, 20) as $i) {
        Video::factory()->owner($product)->create(['sort' => $i]);
    }

    expect(fn () => app(AttachVideo::class)->handle($product, 'abcdefghijk'))->toThrow(ValidationException::class)
        ->and(Video::query()->ownedBy($product)->count())->toBe(20);
});

it('refuses owners outside the allow-list', function () {
    expect(fn () => app(AttachVideo::class)->handle(Page::factory()->create(), 'abcdefghijk'))->toThrow(ValidationException::class);
});

it('enforces the brand rule, length and date range on optional fields', function (array $data) {
    $product = Product::factory()->create();

    expect(fn () => app(AttachVideo::class)->handle($product, 'abcdefghijk', $data))->toThrow(ValidationException::class);
})->with([
    'discount title' => [['title' => ['uz' => 'Arzon narxlar, chegirma']]],
    'ru discount' => [['title' => ['ru' => 'Скидки на принтеры']]],
    'long title' => [['title' => ['uz' => str_repeat('a', 201)]]],
    'title not array' => [['title' => 'x']],
    'unknown locale' => [['title' => ['de' => 'x']]],
    'future date' => [['published_on' => now()->addDay()->format('Y-m-d')]],
    'too old' => [['published_on' => '2005-04-22']],
    'garbage date' => [['published_on' => '2024-13-45']],
    'array date' => [['published_on' => ['2024-01-01']]],
]);

it('cleans titles of control characters and tags', function () {
    $video = app(AttachVideo::class)->handle(Product::factory()->create(), 'abcdefghijk', [
        'title' => ['uz' => "Sozlash\x01 <b>qoʻllanma</b>"],
        'published_on' => '2024-05-01',
    ]);

    expect($video->getTranslation('title', 'uz'))->toBe('Sozlash qoʻllanma')
        ->and($video->published_on->format('Y-m-d'))->toBe('2024-05-01');
});

it('marks the thumbnail as cached when the file already exists', function () {
    VideoThumbnails::store('abcdefghijk', (function () {
        $image = imagecreatetruecolor(640, 360);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    })());

    $video = app(AttachVideo::class)->handle(Product::factory()->create(), 'abcdefghijk');

    expect($video->thumbnail_at)->not->toBeNull();
});

it('touches the owner only for public changes', function () {
    $product = Product::factory()->create();
    $old = oldTimestamp($product);

    $draft = app(AttachVideo::class)->handle($product, 'abcdefghijk', [], active: false);
    expect(touchedAt($product, $old))->toBeFalse();

    app(UpdateVideo::class)->handle($draft, ['title' => ['uz' => 'Yangi']]);
    expect(touchedAt($product, $old))->toBeFalse();

    app(UpdateVideo::class)->handle($draft, ['is_active' => true]);
    expect(touchedAt($product, $old))->toBeTrue();

    $old = oldTimestamp($product);
    app(UpdateVideo::class)->handle($draft->fresh(), ['is_active' => true]);
    expect(touchedAt($product, $old))->toBeFalse();

    app(UpdateVideo::class)->handle($draft->fresh(), ['is_active' => false]);
    expect(touchedAt($product, $old))->toBeTrue();

    $active = app(AttachVideo::class)->handle(Product::factory()->create(), 'abcdefghijk');
    $other = Product::factory()->create();
    $old = oldTimestamp($other);
    app(AttachVideo::class)->handle($other, 'bbbbbbbbbbb');
    expect(touchedAt($other, $old))->toBeTrue();
    expect($active->is_active)->toBeTrue();
});

it('moves videos by swapping and renumbering', function () {
    $product = Product::factory()->create();
    $a = Video::factory()->owner($product)->create(['sort' => 5]);
    $b = Video::factory()->owner($product)->create(['sort' => 9]);
    $c = Video::factory()->owner($product)->create(['sort' => 12]);

    app(MoveVideo::class)->handle($c, -1);
    expect($product->videos()->pluck('id')->all())->toBe([$a->id, $c->id, $b->id])
        ->and($product->videos()->pluck('sort')->all())->toBe([1, 2, 3]);

    app(MoveVideo::class)->handle($a, -1);
    expect($product->videos()->pluck('id')->all())->toBe([$a->id, $c->id, $b->id]);
});

it('deletes a video, its orphan thumbnail and touches a public owner', function () {
    $product = Product::factory()->create();
    $old = oldTimestamp($product);
    $video = Video::factory()->owner($product)->withThumbnail()->create(['youtube_id' => 'abcdefghijk']);
    Storage::disk('public')->put(VideoThumbnails::path('abcdefghijk'), 'x');

    app(DeleteVideo::class)->handle($video);

    expect(Video::query()->count())->toBe(0)
        ->and(Storage::disk('public')->exists(VideoThumbnails::path('abcdefghijk')))->toBeFalse()
        ->and(touchedAt($product, $old))->toBeTrue();
});

it('limits refreshes to one a minute per video and ten a minute per user', function () {
    RateLimiter::clear('video-refresh:1');
    $user = staffUser(Role::ContentManager);
    $video = Video::factory()->create(['fetch_status' => VideoFetchStatus::Failed]);

    app(RefreshVideoDetails::class)->handle($video, $user);

    expect($video->fresh()->fetch_status)->toBe(VideoFetchStatus::Pending)
        ->and(fn () => app(RefreshVideoDetails::class)->handle($video, $user))->toThrow(ValidationException::class);
    Queue::assertPushed(FetchVideoDetails::class, 1);

    foreach (Video::factory()->count(11)->create() as $other) {
        try {
            app(RefreshVideoDetails::class)->handle($other, $user);
        } catch (ValidationException) {
        }
    }

    expect(RateLimiter::attempts('video-refresh-user:'.$user->id))->toBeGreaterThan(10);
    // Every attempt counts (1 + 1 throttled + 8 more reach the limit of 10).
    Queue::assertPushed(FetchVideoDetails::class, 9);
});

it('follows the owner permissions (policy)', function (Role $role, string $ownerKind, bool $view, bool $manage) {
    $owner = match ($ownerKind) {
        'hardware' => Product::factory()->create(),
        'service' => Product::factory()->create(['type' => ProductType::Service]),
        'category' => Category::factory()->create(),
        'brand' => Brand::factory()->create(),
        'post' => NewsPost::factory()->create(),
    };
    $user = staffUser($role);
    $video = Video::factory()->owner($owner)->create();

    expect(Gate::forUser($user)->allows('viewAny', [Video::class, $owner]))->toBe($view)
        ->and(Gate::forUser($user)->allows('create', [Video::class, $owner]))->toBe($manage)
        ->and(Gate::forUser($user)->allows('update', $video))->toBe($manage)
        ->and(Gate::forUser($user)->allows('delete', $video))->toBe($manage);
})->with([
    [Role::Admin, 'hardware', true, true],
    [Role::Admin, 'post', true, true],
    [Role::ContentManager, 'hardware', true, true],
    [Role::ContentManager, 'category', true, true],
    [Role::ContentManager, 'brand', true, true],
    [Role::ContentManager, 'post', true, true],
    [Role::SalesManager, 'hardware', true, false],
    [Role::SalesManager, 'category', true, false],
    [Role::SalesManager, 'brand', true, false],
    [Role::ServiceEngineer, 'service', true, true],
    [Role::ServiceEngineer, 'hardware', true, false],
    [Role::ServiceEngineer, 'category', true, false],
]);

it('denies a video whose owner is gone and unknown owners', function () {
    $user = staffUser(Role::ContentManager);
    $video = Video::factory()->create(['videoable_id' => 999999]);

    expect((new VideoPolicy)->update($user, $video))->toBeFalse()
        ->and((new VideoPolicy)->viewAny($user, Page::factory()->create()))->toBeFalse()
        ->and((new VideoPolicy)->viewAny($user, null))->toBeFalse();
});
