<?php

use App\Actions\Cart\AddToCart;
use App\Enums\NewsKind;
use App\Enums\SaleMode;
use App\Models\Banner;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\NewsPost;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Support\CompanySettings;
use App\Support\DemoContent;
use App\Support\Site\BannerDirectory;
use App\Support\Site\LlmsTxt;
use App\Support\Site\NewsDirectory;
use App\Support\Site\PageDirectory;
use App\Support\Site\Sitemap;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBannersSeeder;
use Database\Seeders\DemoLeadsSeeder;
use Database\Seeders\DemoNewsSeeder;
use Database\Seeders\DemoProductsSeeder;
use Database\Seeders\DemoSeeding;
use Database\Seeders\LaunchSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    config(['admin.seed_login' => 'owner@poson.test', 'admin.seed_password' => 'fake-Launch-pw-2026']);
});

/**
 * Run a seeder class the way db:seed would, in the given environment.
 */
function runSeederIn(string $environment, string $class): void
{
    app()['env'] = $environment;

    app($class)->setContainer(app())->__invoke();
}

it('refuses to run DatabaseSeeder in production and seeds nothing', function () {
    expect(fn () => runSeederIn('production', DatabaseSeeder::class))
        ->toThrow(RuntimeException::class, 'LaunchSeeder');

    expect(User::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and(Product::withTrashed()->count())->toBe(0);
});

it('seeds products, news and banners in production but no leads, customers, quotes or extra users', function () {
    runSeederIn('production', LaunchSeeder::class);

    expect(Product::query()->count())->toBe(count(DemoContent::productSkus()))
        ->and(NewsPost::query()->ofKind(NewsKind::News)->count())->toBe(count(DemoContent::NEWS_SLUGS))
        ->and(Banner::query()->count())->toBe(count(DemoContent::BANNERS))
        ->and(Lead::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0)
        ->and(Quote::query()->count())->toBe(0)
        ->and(User::query()->pluck('email')->all())->toBe(['owner@poson.test']);
});

it('keeps the demo opt-in to LaunchSeeder: demo seeders called directly in production seed nothing', function (string $seeder) {
    $this->seed(CatalogSeeder::class);

    runSeederIn('production', $seeder);

    expect(DemoSeeding::allowed())->toBeFalse()
        ->and(NewsPost::query()->ofKind(NewsKind::News)->count())->toBe(0)
        ->and(Banner::query()->count())->toBe(0)
        ->and(Lead::query()->count())->toBe(0)
        ->and(Product::query()->count())->toBe(0);
})->with([DemoProductsSeeder::class, DemoNewsSeeder::class, DemoBannersSeeder::class, DemoLeadsSeeder::class]);

it('never seeds demo leads, even inside the opt-in', function () {
    $this->seed(CatalogSeeder::class);
    app()['env'] = 'production';

    DemoSeeding::allowingProduction(fn () => app(DemoLeadsSeeder::class)->setContainer(app())->__invoke());

    expect(Lead::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0);
});

it('leaves edited products alone on a second run in production', function () {
    runSeederIn('production', LaunchSeeder::class);

    $product = Product::query()->orderBy('id')->firstOrFail();
    $product->update(['price' => 7_777_700]);
    $trashed = Product::query()->orderByDesc('id')->firstOrFail();
    $trashed->delete();
    $count = Product::withTrashed()->count();

    runSeederIn('production', LaunchSeeder::class);

    expect(Product::withTrashed()->count())->toBe($count)
        ->and($product->fresh()->price)->toBe(7_777_700)
        ->and($trashed->fresh()->trashed())->toBeTrue()
        ->and(User::query()->count())->toBe(1)
        ->and(Banner::query()->count())->toBe(count(DemoContent::BANNERS))
        ->and(NewsPost::query()->ofKind(NewsKind::News)->count())->toBe(count(DemoContent::NEWS_SLUGS));
});

it('runs with model events on and flushes the site caches at the end', function () {
    expect(class_uses_recursive(LaunchSeeder::class))->not->toContain(WithoutModelEvents::class);

    $keys = [Sitemap::CACHE_KEY, LlmsTxt::CACHE_KEY, NewsDirectory::CACHE_KEY, PageDirectory::CACHE_KEY, BannerDirectory::CACHE_KEY, CompanySettings::CACHE_KEY];

    foreach ($keys as $key) {
        Cache::forever($key, 'stale');
    }

    runSeederIn('production', LaunchSeeder::class);

    foreach ($keys as $key) {
        expect(Cache::get($key))->not->toBe('stale');
    }
});

it('makes every demo product quote-only in production, so none can be added to the cart', function () {
    config(['site.cart_enabled' => true]);
    runSeederIn('production', LaunchSeeder::class);

    $products = Product::query()->with('rollTypes')->where('is_active', true)->get();

    expect($products)->not->toBeEmpty()
        ->and($products->every(fn (Product $product): bool => $product->sale_mode === SaleMode::Quote))->toBeTrue();

    foreach ($products as $product) {
        expect(fn () => app(AddToCart::class)->handle((string) Str::ulid(), $product->id, $product->rollTypes->first()?->id, max(1, $product->min_qty), 'uz'))
            ->toThrow(ValidationException::class);
    }
});

it('seeds brand-safe demo copy', function () {
    runSeederIn('production', LaunchSeeder::class);

    $texts = collect();

    foreach (Product::query()->get() as $product) {
        foreach (['name', 'short_description', 'description', 'meta_title', 'meta_description'] as $field) {
            $texts->push(...array_values($product->getTranslations($field)));
        }
    }

    foreach (NewsPost::query()->ofKind(NewsKind::News)->get() as $post) {
        foreach (['title', 'excerpt', 'body'] as $field) {
            $texts->push(...array_values($post->getTranslations($field)));
        }
    }

    foreach (Banner::query()->get() as $banner) {
        foreach (['title', 'text', 'button_label'] as $field) {
            $texts->push(...array_values($banner->getTranslations($field)));
        }
    }

    expect($texts)->not->toBeEmpty();

    foreach ($texts as $text) {
        expect(BrandSafeText::violates(strip_tags((string) $text)))->toBeFalse();
    }
});
