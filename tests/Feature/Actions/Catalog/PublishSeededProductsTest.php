<?php

use App\Actions\Catalog\AddProductRelation;
use App\Actions\Catalog\PublishSeededProducts;
use App\Actions\Catalog\UnpublishSeededProducts;
use App\Enums\AuditAction;
use App\Enums\RelationType;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Support\Site\LlmsTxt;
use App\Support\Site\Sitemap;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ProductsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(CatalogSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function publishRow(string $sku, array $overrides = []): array
{
    return [
        'sku' => $sku, 'model' => 'M-'.$sku, 'brand' => 'ZEBRA', 'category' => 'receipt-printers', 'type' => 'hardware',
        'unit' => 'piece', 'min_qty' => 1, 'sale_mode' => 'both', 'stock_status' => 'on_order', 'warranty_months' => 12,
        'badge' => null, 'is_featured' => false, 'price' => 1_450_000,
        'name' => ['uz' => 'Printer '.$sku, 'ru' => 'Принтер '.$sku, 'en' => 'Printer '.$sku],
        'short_description' => ['uz' => 'Qisqa', 'ru' => 'Кратко', 'en' => 'Short'],
        'description' => ['uz' => '<p>Tavsif</p>', 'ru' => '<p>Описание</p>', 'en' => '<p>Description</p>'],
        'meta_title' => ['uz' => 'Sarlavha', 'ru' => 'Заголовок', 'en' => 'Title'],
        'meta_description' => ['uz' => 'Meta', 'ru' => 'Мета', 'en' => 'Meta'],
        'specs' => ['interface' => ['USB'], 'paper_width' => '80'], 'related' => [], 'has_missing_specs' => false, 'suspicious_price' => false,
        ...$overrides,
    ];
}

/**
 * Seed rows as the OLD seeder did: drafts, created a day ago and never touched since.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function seedOldDrafts(array $rows): void
{
    $seeder = app(ProductsSeeder::class);
    $seeder->rows = $rows;
    $seeder->setContainer(app())->__invoke();

    $day = now()->subDay();
    DB::table('products')->update(['is_active' => false, 'created_at' => $day, 'updated_at' => $day]);
    DB::table('categories')->whereIn('slug', PublishSeededProducts::CATEGORY_SLUGS)->update(['is_active' => false, 'created_at' => $day, 'updated_at' => $day]);
}

function publishSeeded(?array $rows = null): array
{
    $action = app(PublishSeededProducts::class);
    $action->rows = $rows;

    return $action->handle();
}

it('publishes an untouched complete draft, keeps a missing-spec one as a draft, and publishes no-price as a quote product (P1)', function () {
    seedOldDrafts([publishRow('P-1'), publishRow('P-2', ['specs' => []]), publishRow('P-3', ['price' => null])]);

    $stats = publishSeeded();

    expect($stats['published'])->toBe(2)
        ->and($stats['still_draft'])->toBe(1)
        ->and(Product::query()->where('sku', 'P-1')->value('is_active'))->toBeTrue()
        ->and(Product::query()->where('sku', 'P-2')->value('is_active'))->toBeFalse();

    $quote = Product::query()->where('sku', 'P-3')->sole();
    expect($quote->is_active)->toBeTrue()->and($quote->price)->toBeNull();

    $html = $this->get('/en/product/'.$quote->slug)->assertOk()->getContent();
    expect($html)->toContain(__('site.card.price_on_request', [], 'en'))
        ->and($html)->not->toContain('wire:click="addToCart"');
});

it('skips what breaks a P2 exclusion', function (string $exclusion) {
    seedOldDrafts([publishRow('X-1'), publishRow('X-2')]);
    $product = Product::query()->where('sku', 'X-1')->sole();

    match ($exclusion) {
        'not recorded' => Setting::query()->where('key', ProductsSeeder::RECORD_KEY)->update(['value' => ['skus' => ['X-2']]]),
        'trashed' => $product->delete(),
        'price synced' => DB::table('products')->where('id', $product->id)->update(['price_synced_at' => now()]),
        'edited later' => DB::table('products')->where('id', $product->id)->update(['updated_at' => now()]),
        'media added' => (function () use ($product) {
            Storage::fake('public');
            Storage::fake('local');
            $product->addMedia(UploadedFile::fake()->image('a.jpg', 600, 600))->toMediaCollection(Product::IMAGES);
        })(),
        'document added' => ProductDocument::factory()->create(['product_id' => $product->id]),
    };

    publishSeeded();

    expect(Product::withTrashed()->where('sku', 'X-1')->value('is_active'))->toBeFalsy()
        ->and(Product::query()->where('sku', 'X-2')->value('is_active'))->toBeTrue();
})->with(['not recorded', 'trashed', 'price synced', 'edited later', 'media added', 'document added']);

it('runs once: a product an admin deactivated is not published again (P3)', function () {
    seedOldDrafts([publishRow('R-1')]);
    publishSeeded();

    expect(Setting::query()->where('key', PublishSeededProducts::MARKER_KEY)->exists())->toBeTrue();

    $product = Product::query()->where('sku', 'R-1')->sole();
    $product->update(['is_active' => false]);

    $stats = publishSeeded();

    expect($stats['ran'])->toBeFalse()
        ->and($product->fresh()->is_active)->toBeFalse();
});

it('activates only the import categories that are untouched and hold an active seeded product (P4)', function () {
    seedOldDrafts([
        publishRow('C-1', ['category' => 'price-checkers']),
        publishRow('C-2', ['category' => 'accessories']),
        publishRow('C-3', ['category' => 'textile-tapes', 'specs' => []]),
    ]);

    // An admin touched textile-tapes (and it only has a draft anyway); spare-parts has nothing.
    $stats = publishSeeded();
    $active = fn (string $slug): bool => (bool) Category::query()->where('slug', $slug)->value('is_active');

    expect($active('price-checkers'))->toBeTrue()
        ->and($active('accessories'))->toBeTrue()
        ->and($active('accessories-parts'))->toBeTrue()
        ->and($active('spare-parts'))->toBeFalse()
        ->and($stats['categories'])->toContain('price-checkers', 'accessories', 'accessories-parts');
});

it('leaves a category an admin touched, and any other category, alone (P4)', function () {
    seedOldDrafts([publishRow('T-1', ['category' => 'price-checkers'])]);
    DB::table('categories')->where('slug', 'price-checkers')->update(['updated_at' => now()]);
    $other = Category::query()->where('slug', 'scanners')->value('is_active');

    publishSeeded();

    expect(Category::query()->where('slug', 'price-checkers')->value('is_active'))->toBeFalsy()
        ->and(Category::query()->where('slug', 'scanners')->value('is_active'))->toBe($other);
});

it('activates the categories on a fresh install, right after the seeder created active products (P4)', function () {
    $seeder = app(ProductsSeeder::class);
    $seeder->rows = [publishRow('F-1', ['category' => 'spare-parts']), publishRow('F-2', ['category' => 'textile-tapes', 'specs' => []])];
    $seeder->setContainer(app())->__invoke();

    publishSeeded();

    expect(Category::query()->where('slug', 'spare-parts')->value('is_active'))->toBeTrue()
        ->and(Category::query()->where('slug', 'accessories-parts')->value('is_active'))->toBeTrue()
        ->and(Category::query()->where('slug', 'price-checkers')->value('is_active'))->toBeFalsy();
});

it('writes no timestamps, audits as the system and flushes the site caches once (P5, P6)', function () {
    seedOldDrafts([publishRow('S-1')]);
    $before = Product::query()->where('sku', 'S-1')->value('updated_at');

    foreach ([Sitemap::CACHE_KEY, LlmsTxt::CACHE_KEY] as $key) {
        Cache::forever($key, 'stale');
    }

    publishSeeded();

    $product = Product::query()->where('sku', 'S-1')->sole();
    $audit = SettingsAudit::query()->where('action', AuditAction::Published)->sole();

    expect($product->updated_at->equalTo($before))->toBeTrue()
        ->and($audit->user_id)->toBeNull()
        ->and($audit->area)->toBe(SettingsArea::Catalog)
        ->and($audit->key)->toBe('launch-seeder.publish_seeded')
        ->and(Cache::get(Sitemap::CACHE_KEY))->not->toBe('stale')
        ->and(Cache::get(LlmsTxt::CACHE_KEY))->not->toBe('stale')
        ->and(app(Sitemap::class)->xml())->toContain($product->slug);
});

it('re-applies the current data specs to an untouched draft before publishing it, without touching anything else (P2)', function () {
    seedOldDrafts([publishRow('RS-1', ['specs' => []]), publishRow('RS-2', ['specs' => []])]);
    DB::table('products')->where('sku', 'RS-2')->update(['updated_at' => now()]);
    $before = Product::query()->where('sku', 'RS-1')->sole();

    $stats = publishSeeded([publishRow('RS-1'), publishRow('RS-2')]);

    $after = Product::query()->where('sku', 'RS-1')->sole();

    expect($stats['respecced'])->toBe(1)
        ->and($after->is_active)->toBeTrue()
        ->and($after->has_missing_specs)->toBeFalse()
        ->and($after->attributeValues()->count())->toBe(2)
        ->and($after->price)->toBe($before->price)
        ->and($after->getTranslations('name'))->toBe($before->getTranslations('name'))
        ->and($after->slug)->toBe($before->slug)
        ->and($after->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and(Product::query()->where('sku', 'RS-2')->sole()->attributeValues()->count())->toBe(0)
        ->and(Product::query()->where('sku', 'RS-2')->value('is_active'))->toBeFalse();
});

it('rolls back for an Admin only, idempotently and audited (P7)', function () {
    seedOldDrafts([publishRow('B-1', ['category' => 'price-checkers']), publishRow('B-2')]);
    publishSeeded();
    DB::table('products')->where('sku', 'B-2')->update(['updated_at' => now()]);
    $admin = staffUser(Role::Admin);

    expect(fn () => app(UnpublishSeededProducts::class)->handle(staffUser(Role::ContentManager)))->toThrow(AuthorizationException::class);

    $result = app(UnpublishSeededProducts::class)->handle($admin);
    $again = app(UnpublishSeededProducts::class)->handle($admin);

    expect($result['products'])->toBe(1)
        ->and($result['categories'])->toContain('price-checkers')
        ->and(Product::query()->where('sku', 'B-1')->value('is_active'))->toBeFalse()
        ->and(Product::query()->where('sku', 'B-2')->value('is_active'))->toBeTrue()
        ->and(Category::query()->where('slug', 'price-checkers')->value('is_active'))->toBeFalse()
        ->and($again['products'])->toBe(0)
        ->and(SettingsAudit::query()->where('action', AuditAction::Unpublished)->first()->user_id)->toBe($admin->id)
        ->and(publishSeeded()['ran'])->toBeFalse();
});

it('has an Admin-only command for the rollback that needs --force in production (P7)', function () {
    seedOldDrafts([publishRow('K-1')]);
    publishSeeded();
    $admin = staffUser(Role::Admin);

    $this->artisan('catalog:unpublish-seeded', ['--user' => staffUser(Role::SalesManager)->id, '--force' => true])->assertFailed();
    expect(Product::query()->where('sku', 'K-1')->value('is_active'))->toBeTrue();

    $this->artisan('catalog:unpublish-seeded', ['--user' => $admin->id, '--force' => true])->assertSuccessful();
    expect(Product::query()->where('sku', 'K-1')->value('is_active'))->toBeFalse()
        ->and(SettingsAudit::query()->where('action', AuditAction::Unpublished)->sole()->user_id)->toBe($admin->id);

    app()['env'] = 'production';
    $this->artisan('catalog:unpublish-seeded', ['--user' => $admin->id])->assertFailed();
});

it('treats a relation added without saving the product as an admin edit (M1)', function () {
    seedOldDrafts([publishRow('L-1'), publishRow('L-2')]);
    $one = Product::query()->where('sku', 'L-1')->sole();

    app(AddProductRelation::class)->handle($one, Product::query()->where('sku', 'L-2')->sole(), RelationType::Accessory);

    $stats = publishSeeded();

    expect($stats['skipped_edited'])->toBe(1)
        ->and(Product::query()->where('sku', 'L-1')->value('is_active'))->toBeFalsy()
        ->and(Product::query()->where('sku', 'L-2')->value('is_active'))->toBeTrue();
});

it('counts updated_at up to 60 s after created_at as untouched and 61 s as touched', function (int $seconds, bool $published) {
    seedOldDrafts([publishRow('T-60')]);
    $created = Product::query()->where('sku', 'T-60')->value('created_at');
    DB::table('products')->update(['updated_at' => Carbon::parse($created)->addSeconds($seconds)]);

    publishSeeded();

    expect((bool) Product::query()->where('sku', 'T-60')->value('is_active'))->toBe($published);
})->with([[60, true], [61, false]]);

it('does not activate a category whose parent is inactive and not an import category (code 7)', function () {
    seedOldDrafts([publishRow('G-1', ['category' => 'price-checkers'])]);
    DB::table('categories')->where('slug', 'pos')->update(['is_active' => false]);

    publishSeeded();

    expect(Category::query()->where('slug', 'price-checkers')->value('is_active'))->toBeFalsy();
});

it('records the leftover drafts in the marker and never retries them', function () {
    seedOldDrafts([publishRow('D-1', ['specs' => []])]);
    publishSeeded();

    $marker = Setting::query()->where('key', PublishSeededProducts::MARKER_KEY)->value('value');

    expect($marker['still_draft_skus'])->toBe(['D-1'])
        ->and($marker['published'])->toBe(0);
});
