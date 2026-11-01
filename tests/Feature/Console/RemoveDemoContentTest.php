<?php

use App\Enums\NewsKind;
use App\Models\Banner;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\Setting;
use App\Support\DemoContent;
use Database\Seeders\LaunchSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    config(['admin.seed_login' => 'owner@poson.test', 'admin.seed_password' => 'fake-Launch-pw-2026']);

    withoutRealProducts();
    app()['env'] = 'production';
    app(LaunchSeeder::class)->setContainer(app())->__invoke();
    seedRecordedLegacyDemoProducts();
});

it('removes only the demo products, news and banners', function () {
    $real = Product::factory()->create(['sku' => 'REAL-001']);
    $realPost = NewsPost::factory()->create(['slug' => 'real-news']);
    $realBanner = Banner::factory()->create(['link' => '/catalog']);
    Product::query()->where('sku', 'ZEB-DS2208-SR')->firstOrFail()->delete();

    $this->artisan('demo:remove', ['--force' => true])->assertSuccessful();

    expect(Product::withTrashed()->pluck('sku')->all())->toBe(['REAL-001'])
        ->and(NewsPost::query()->ofKind(NewsKind::News)->pluck('id')->all())->toBe([$realPost->id])
        ->and(Banner::query()->pluck('id')->all())->toBe([$realBanner->id])
        ->and($real->fresh())->not->toBeNull()
        // Articles are not demo content.
        ->and(NewsPost::query()->ofKind(NewsKind::Article)->count())->toBeGreaterThan(0);
});

it('requires --force in production', function () {
    $count = Product::query()->count();

    $this->artisan('demo:remove')->assertFailed();

    expect(Product::query()->count())->toBe($count)->and($count)->toBeGreaterThan(0);
});

it('only lists what it would remove with --dry-run', function () {
    $count = Product::query()->count();

    $this->artisan('demo:remove', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect(Product::query()->count())->toBe($count)
        ->and(Banner::query()->count())->toBe(2);
});

it('asks for confirmation outside production', function () {
    app()['env'] = 'local';

    $this->artisan('demo:remove')->expectsConfirmation('Permanently delete this demo content?', 'no')->assertFailed();
    expect(Product::query()->count())->toBeGreaterThan(0);

    $this->artisan('demo:remove')->expectsConfirmation('Permanently delete this demo content?', 'yes')->assertSuccessful();
    expect(Product::withTrashed()->count())->toBe(0)
        ->and(Banner::query()->count())->toBe(0);
});

it('keeps a product created with a demo SKU after the removal (second run finds nothing)', function () {
    $this->artisan('demo:remove', ['--force' => true])->assertSuccessful();

    expect(Setting::query()->where('key', DemoContent::KEY)->exists())->toBeFalse();

    $real = Product::factory()->create(['sku' => 'ZEB-DS2208-SR']);

    $this->artisan('demo:remove', ['--force' => true])->assertSuccessful();

    expect($real->fresh())->not->toBeNull();
});

it('removes nothing without a launch_demo_content record', function () {
    Setting::query()->where('key', DemoContent::KEY)->delete();
    $count = Product::query()->count();

    $this->artisan('demo:remove', ['--force' => true])->assertSuccessful();

    expect(Product::query()->count())->toBe($count)
        ->and(NewsPost::query()->ofKind(NewsKind::News)->count())->toBe(3)
        ->and(Banner::query()->count())->toBe(2);
});

it('skips an edited demo product unless --include-edited', function () {
    $product = Product::query()->where('sku', 'ZEB-DS2208-SR')->firstOrFail();
    $this->travel(30)->minutes();
    $product->forceFill(['is_featured' => ! $product->is_featured])->save();
    $count = Product::query()->count();

    $this->artisan('demo:remove', ['--force' => true])->expectsOutputToContain('Skipped')->assertSuccessful();

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->where('sku', 'ZEB-DS2208-SR')->exists())->toBeTrue()
        ->and($count)->toBeGreaterThan(1)
        // The record stays so the edited row can still be removed.
        ->and(Setting::query()->where('key', DemoContent::KEY)->exists())->toBeTrue();

    $this->artisan('demo:remove', ['--force' => true, '--include-edited' => true])->assertSuccessful();

    expect(Product::withTrashed()->count())->toBe(0)
        ->and(Setting::query()->where('key', DemoContent::KEY)->exists())->toBeFalse();
});

it('skips an edited demo news post', function () {
    $post = NewsPost::query()->where('slug', DemoContent::NEWS_SLUGS[0])->firstOrFail();
    $this->travel(30)->minutes();
    $post->touch();

    $this->artisan('demo:remove', ['--force' => true])->assertSuccessful();

    expect(NewsPost::query()->ofKind(NewsKind::News)->pluck('slug')->all())->toBe([DemoContent::NEWS_SLUGS[0]]);
});

it('does not recreate demo news, banners or products when LaunchSeeder runs again after the removal', function () {
    $this->artisan('demo:remove', ['--force' => true])->assertSuccessful();

    app(LaunchSeeder::class)->setContainer(app())->__invoke();

    expect(NewsPost::query()->ofKind(NewsKind::News)->count())->toBe(0)
        ->and(Banner::query()->count())->toBe(0)
        ->and(Product::withTrashed()->count())->toBe(0);
});

it('keeps the original seeded_at when a seeder runs again, so an edited product stays skipped', function () {
    $product = Product::query()->where('sku', 'ZEB-DS2208-SR')->firstOrFail();
    $this->travel(30)->minutes();
    $product->forceFill(['is_featured' => ! $product->is_featured])->save();

    NewsPost::query()->ofKind(NewsKind::News)->get()->each->delete();
    app(LaunchSeeder::class)->setContainer(app())->__invoke();

    $this->artisan('demo:remove', ['--force' => true])->assertSuccessful();

    expect(Product::query()->where('sku', 'ZEB-DS2208-SR')->exists())->toBeTrue();
});
