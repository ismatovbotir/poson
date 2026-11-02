<?php

use App\Enums\ProductType;
use App\Enums\Role;
use App\Enums\VideoFetchStatus;
use App\Jobs\FetchVideoDetails;
use App\Livewire\Admin\Videos\VideoList;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $params
 */
function videoEditor(User $user, array $params): Testable
{
    return Livewire::actingAs($user)->test(VideoList::class, $params);
}

it('lets writers add, edit, reorder, toggle, refresh and delete videos', function (string $kind, Role $role) {
    $owner = match ($kind) {
        'product' => Product::factory()->create(),
        'category' => Category::factory()->create(),
        'brand' => Brand::factory()->create(),
        'newsPost' => NewsPost::factory()->create(),
    };
    $editor = videoEditor(staffUser($role), [$kind => $owner]);

    $editor->set('form.url', 'https://youtu.be/abcdefghijk')->call('add')->assertHasNoErrors()->assertDispatched('toast');
    $editor->set('form.url', 'https://www.youtube.com/watch?v=bbbbbbbbbbb')->set('form.title.uz', 'Sozlash')->call('add')->assertHasNoErrors();
    Queue::assertPushed(FetchVideoDetails::class, 2);

    [$first, $second] = $owner->videos()->get()->all();
    expect($first->youtube_id)->toBe('abcdefghijk')->and($second->getTranslation('title', 'uz'))->toBe('Sozlash');

    $editor->call('move', $second->id, -1);
    expect($owner->videos()->pluck('id')->all())->toBe([$second->id, $first->id]);

    $editor->call('edit', $first->id)->set('form.title.ru', 'Настройка')->set('form.published_on', '2024-03-02')->call('save')->assertHasNoErrors();
    expect($first->refresh()->getTranslation('title', 'ru'))->toBe('Настройка')->and($first->published_on->format('Y-m-d'))->toBe('2024-03-02');

    $editor->call('toggle', $first->id);
    expect($first->refresh()->is_active)->toBeFalse();

    $editor->call('refresh', $second->id)->assertDispatched('toast');
    expect($second->refresh()->fetch_status)->toBe(VideoFetchStatus::Pending);

    $editor->call('delete', $first->id)->assertDispatched('toast');
    expect($owner->videos()->count())->toBe(1);
})->with(function () {
    foreach (['product', 'category', 'brand', 'newsPost'] as $kind) {
        yield "$kind admin" => [$kind, Role::Admin];
        yield "$kind content manager" => [$kind, Role::ContentManager];
    }
});

it('is read-only for view-only roles and forbids every write', function (Role $role) {
    $product = Product::factory()->create();
    $video = Video::factory()->owner($product)->create();
    $user = staffUser($role);

    videoEditor($user, ['product' => $product])->assertOk()->assertSee('data-video-read-only', false)->assertDontSee('data-video-add-button', false);

    foreach (['edit' => [$video->id], 'toggle' => [$video->id], 'move' => [$video->id, 1], 'refresh' => [$video->id], 'delete' => [$video->id]] as $method => $args) {
        videoEditor($user, ['product' => $product])->call($method, ...$args)->assertForbidden();
    }

    videoEditor($user, ['product' => $product])->set('form.url', 'abcdefghijk')->call('add')->assertForbidden();
    expect(Video::query()->count())->toBe(1);
})->with([Role::SalesManager]);

it('limits the service engineer to software and service products', function () {
    $user = staffUser(Role::ServiceEngineer);
    $service = Product::factory()->create(['type' => ProductType::Service]);
    $hardware = Product::factory()->create();

    videoEditor($user, ['product' => $service])->set('form.url', 'abcdefghijk')->call('add')->assertHasNoErrors();
    videoEditor($user, ['product' => $hardware])->set('form.url', 'abcdefghijk')->call('add')->assertForbidden();
    videoEditor($user, ['category' => Category::factory()->create()])->set('form.url', 'abcdefghijk')->call('add')->assertForbidden();
});

it('refuses a mount without an owner or with a user who cannot view it', function () {
    videoEditor(staffUser(), [])->assertNotFound();
    Livewire::actingAs(User::factory()->create())->test(VideoList::class, ['product' => Product::factory()->create()])->assertForbidden();
});

it('returns 404 for a video of another owner', function () {
    $product = Product::factory()->create();
    $foreign = Video::factory()->create();
    $admin = staffUser();

    foreach (['edit', 'toggle', 'refresh', 'delete'] as $method) {
        videoEditor($admin, ['product' => $product])->call($method, $foreign->id)->assertNotFound();
    }

    videoEditor($admin, ['product' => $product])->call('move', $foreign->id, 1)->assertNotFound();
    expect(Video::query()->count())->toBe(1);
});

it('keeps the owner properties locked', function () {
    $product = Product::factory()->create();

    expect(fn () => videoEditor(staffUser(), ['product' => $product])->set('product', 2))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => videoEditor(staffUser(), ['product' => $product])->set('editingId', 2))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('turns tampered input into validation errors, not exceptions', function (string $field, mixed $value) {
    $product = Product::factory()->create();

    videoEditor(staffUser(), ['product' => $product])->set($field, $value)->set('form.url', $field === 'form.url' ? $value : 'abcdefghijk')->call('add')->assertHasErrors();
    expect(Video::query()->count())->toBe(0);
})->with([
    'url array' => ['form.url', ['a']],
    'url too long' => ['form.url', str_repeat('a', 501)],
    'title string' => ['form.title', 'x'],
    'title extra key' => ['form.title', ['uz' => 'a', 'de' => 'b']],
    'title nested' => ['form.title', ['uz' => ['x']]],
    'title unsafe' => ['form.title', ['uz' => 'Arzon narx', 'ru' => '', 'en' => '']],
    'date text' => ['form.published_on', 'yesterday'],
    'date future' => ['form.published_on', '2999-01-01'],
]);

it('shows invalid-link and duplicate errors without echoing the link', function () {
    $product = Product::factory()->create();
    $editor = videoEditor(staffUser(), ['product' => $product]);

    $editor->set('form.url', 'https://evil.example/x?<script>')->call('add')
        ->assertHasErrors('form.url')
        ->assertDontSee('evil.example');

    $editor->set('form.url', 'abcdefghijk')->call('add')->assertHasNoErrors();
    $editor->set('form.url', 'abcdefghijk')->call('add')->assertHasErrors('form.url');
});

it('polls only while a recent fetch is pending', function () {
    $product = Product::factory()->create();
    $pending = Video::factory()->owner($product)->create();

    videoEditor(staffUser(), ['product' => $product])->assertSeeHtml('wire:poll.5s');

    Video::query()->whereKey($pending->id)->update(['updated_at' => now()->subMinutes(11)]);
    videoEditor(staffUser(), ['product' => $product])->assertDontSeeHtml('wire:poll');

    Video::query()->whereKey($pending->id)->update(['updated_at' => now(), 'fetch_status' => VideoFetchStatus::Ok->value]);
    videoEditor(staffUser(), ['product' => $product])->assertDontSeeHtml('wire:poll');
});

it('throttles refreshes with a toast and shows no google image in the admin html', function () {
    $product = Product::factory()->create();
    $video = Video::factory()->owner($product)->fetched('Bad <script>x</script> title')->create(['youtube_id' => 'abcdefghijk']);
    $editor = videoEditor(staffUser(), ['product' => $product]);

    $html = $editor->html();
    expect($html)->not->toContain('ytimg')
        ->and($html)->not->toContain('<script>x')
        ->and($html)->toContain('https://www.youtube.com/watch?v=abcdefghijk')
        ->and($html)->not->toMatch('#src="[^"]*youtube#i');

    RateLimiter::clear('video-refresh:'.$video->id);
    $editor->call('refresh', $video->id)->assertDispatched('toast');
    $editor->call('refresh', $video->id)->assertDispatched('toast', type: 'error');
});

it('shows the video editor on the four forms after the first save only', function () {
    $admin = staffUser();
    $product = Product::factory()->create();
    $category = Category::factory()->create();
    $brand = Brand::factory()->create();
    $post = NewsPost::factory()->create();

    foreach ([
        route('admin.products.edit', $product),
        route('admin.categories.edit', $category),
        route('admin.brands.edit', $brand),
        route('admin.news.edit', $post),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertOk()->assertSeeLivewire(VideoList::class);
    }

    foreach (['admin.products.create', 'admin.categories.create', 'admin.brands.create', 'admin.news.create'] as $route) {
        $this->actingAs($admin)->get(route($route))->assertOk()->assertDontSeeLivewire(VideoList::class)->assertSeeText(__('admin.videos.save_first'));
    }
});
