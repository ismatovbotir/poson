<?php

use App\Actions\Catalog\ForceDeleteProduct;
use App\Enums\MonitoringAction as MonitoringActionType;
use App\Models\MonitoringAction;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

it('purges only old files in livewire-tmp', function () {
    $disk = Storage::disk('local');
    $disk->put('livewire-tmp/old.png', 'x');
    $disk->put('livewire-tmp/fresh.png', 'x');
    $disk->put('product-documents/doc.pdf', 'x');
    $disk->put('livewire-tmp/sub/nested.png', 'x');
    $disk->put('1/conversions/a.webp', 'x');

    foreach (['livewire-tmp/old.png', 'product-documents/doc.pdf', 'livewire-tmp/sub/nested.png', '1/conversions/a.webp'] as $path) {
        touch($disk->path($path), now()->subHours(48)->getTimestamp());
    }

    $this->artisan('uploads:purge-tmp --dry-run')->assertSuccessful();
    $disk->assertExists('livewire-tmp/old.png');

    $this->artisan('uploads:purge-tmp')->assertSuccessful();

    $disk->assertMissing('livewire-tmp/old.png');
    $disk->assertExists('livewire-tmp/fresh.png');
    $disk->assertExists('product-documents/doc.pdf');
    $disk->assertExists('livewire-tmp/sub/nested.png');
    $disk->assertExists('1/conversions/a.webp');
});

it('schedules both purge commands daily in Tashkent without overlap', function () {
    foreach (['catalog:purge-trash', 'uploads:purge-tmp'] as $name) {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, $name));

        expect($event)->not->toBeNull()
            ->and($event->timezone)->toBe('Asia/Tashkent')
            ->and($event->onOneServer)->toBeTrue()
            ->and($event->withoutOverlapping)->toBeTrue();
    }
});

/**
 * @return array{0: Product, 1: string, 2: ProductDocument}
 */
function trashedProduct(int $days): array
{
    $product = Product::factory()->create();
    $path = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES)->getPathRelativeToRoot();
    $document = ProductDocument::factory()->file()->for($product)->create();
    Storage::disk(ProductDocument::DISK)->put($document->path, 'pdf');
    $product->delete();
    Product::withTrashed()->whereKey($product->id)->update(['deleted_at' => now()->subDays($days)]);

    return [$product, $path, $document];
}

it('force deletes only products trashed for more than 30 days', function () {
    $live = Product::factory()->create();
    $livePath = $live->addMedia(UploadedFile::fake()->image('l.jpg'))->toMediaCollection(Product::IMAGES)->getPathRelativeToRoot();
    [$young, $youngPath] = trashedProduct(29);
    [$old, $oldPath, $oldDocument] = trashedProduct(31);

    $this->artisan('catalog:purge-trash --dry-run')->assertSuccessful();
    expect(Product::withTrashed()->count())->toBe(3);

    $this->artisan('catalog:purge-trash --force')->assertSuccessful();

    expect(Product::withTrashed()->whereKey($live->id)->exists())->toBeTrue()
        ->and(Product::withTrashed()->whereKey($young->id)->exists())->toBeTrue()
        ->and(Product::withTrashed()->whereKey($old->id)->exists())->toBeFalse();
    Storage::disk('local')->assertExists($livePath);
    Storage::disk('local')->assertExists($youngPath);
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertMissing($oldDocument->path);
    expect(Media::query()->where('model_id', $old->id)->count())->toBe(0);

    $audit = MonitoringAction::query()->where('action', MonitoringActionType::TrashPurge)->get();
    expect($audit)->toHaveCount(1)->and($audit->first()->count)->toBe(1);
});

it('keeps order line snapshots when the product is purged', function () {
    [$old] = trashedProduct(40);
    $item = OrderItem::factory()->create(['product_id' => $old->id, 'name' => 'Zebra ZD421', 'sku' => 'ZD421']);

    $this->artisan('catalog:purge-trash --force')->assertSuccessful();

    $item->refresh();
    expect($item->product_id)->toBeNull()->and($item->name)->toBe('Zebra ZD421')->and($item->sku)->toBe('ZD421');
});

it('keeps a product restored after the chunk was selected but before its locked re-check', function () {
    [$first] = trashedProduct(40);
    [$second] = trashedProduct(41);

    $original = app(ForceDeleteProduct::class);
    $this->app->instance(ForceDeleteProduct::class, new class($original, $second->id) extends ForceDeleteProduct
    {
        public function __construct(private ForceDeleteProduct $inner, private int $restoreId) {}

        public function handle(Product $product): void
        {
            // While the first product is deleted, someone restores the second one (same chunk).
            Product::withTrashed()->whereKey($this->restoreId)->update(['deleted_at' => null]);

            $this->inner->handle($product);
        }
    });

    $this->artisan('catalog:purge-trash --force')->assertSuccessful();

    expect(Product::withTrashed()->whereKey($first->id)->exists())->toBeFalse()
        ->and(Product::query()->whereKey($second->id)->exists())->toBeTrue();
});

it('continues after one product fails', function () {
    [$first] = trashedProduct(40);
    [$second, $secondPath] = trashedProduct(41);

    $original = app(ForceDeleteProduct::class);
    $this->app->instance(ForceDeleteProduct::class, new class($original, $first->id) extends ForceDeleteProduct
    {
        public function __construct(private ForceDeleteProduct $inner, private int $failId) {}

        public function handle(Product $product): void
        {
            if ($product->id === $this->failId) {
                throw new RuntimeException('boom');
            }

            $this->inner->handle($product);
        }
    });

    $this->artisan('catalog:purge-trash --force')->assertSuccessful();

    expect(Product::withTrashed()->whereKey($first->id)->exists())->toBeTrue()
        ->and(Product::withTrashed()->whereKey($second->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($secondPath);
});

/**
 * Points media-library at a throwaway temp base and returns it.
 */
function fakeMediaTempBase(): string
{
    $base = sys_get_temp_dir().'/poson-media-temp-'.bin2hex(random_bytes(4));
    mkdir($base.'/temp', 0777, true);
    mkdir($base.'/outside/keep', 0777, true);
    file_put_contents($base.'/outside/keep/file.txt', 'x');
    config(['media-library.temporary_directory_path' => $base.'/temp']);

    return $base;
}

function fakeMediaTempDir(string $base, string $name, int $hoursOld): void
{
    mkdir($base.'/temp/'.$name.'/inner', 0777, true);
    file_put_contents($base.'/temp/'.$name.'/inner/f.txt', 'x');
    touch($base.'/temp/'.$name, now()->subHours($hoursOld)->getTimestamp());
}

it('cleans only stale media temp directories and never follows symlinks', function () {
    $base = fakeMediaTempBase();
    fakeMediaTempDir($base, 'old', 25);
    fakeMediaTempDir($base, 'fresh', 1);
    file_put_contents($base.'/temp/loose.txt', 'x');
    touch($base.'/temp/loose.txt', now()->subHours(48)->getTimestamp());
    symlink($base.'/outside', $base.'/temp/linked');
    fakeMediaTempDir($base, 'nested', 25);
    symlink($base.'/outside', $base.'/temp/nested/inner/link');

    Log::spy();
    $this->artisan('media:clean-temp')->assertSuccessful();

    expect(is_dir($base.'/temp/old'))->toBeFalse()
        ->and(is_dir($base.'/temp/fresh'))->toBeTrue()
        ->and(is_dir($base.'/temp/nested'))->toBeFalse()
        ->and(file_exists($base.'/temp/loose.txt'))->toBeTrue()
        ->and(is_link($base.'/temp/linked'))->toBeTrue()
        ->and(file_exists($base.'/outside/keep/file.txt'))->toBeTrue();

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'media:clean-temp'
        && $context === ['deleted' => 2, 'skipped' => 3, 'failed' => 0, 'dry_run' => false]
        && ! str_contains(json_encode($context), $base))->once();

    File::deleteDirectory($base);
});

it('only counts stale media temp directories with --dry-run', function () {
    $base = fakeMediaTempBase();
    fakeMediaTempDir($base, 'old', 25);
    fakeMediaTempDir($base, 'fresh', 1);

    $this->artisan('media:clean-temp --dry-run')->expectsOutputToContain('Would delete 1')->assertSuccessful();

    expect(is_dir($base.'/temp/old'))->toBeTrue()
        ->and(is_dir($base.'/temp/fresh'))->toBeTrue();

    File::deleteDirectory($base);
});

it('exits cleanly when the media temp base is missing and prints no path', function () {
    config(['media-library.temporary_directory_path' => sys_get_temp_dir().'/poson-missing-'.bin2hex(random_bytes(4))]);

    $this->artisan('media:clean-temp')->assertSuccessful()->doesntExpectOutputToContain(sys_get_temp_dir());
});

it('schedules media:clean-temp daily without overlap', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'media:clean-temp'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toEndWith('* * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
