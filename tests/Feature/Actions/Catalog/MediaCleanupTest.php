<?php

use App\Actions\Banners\DeleteBanner;
use App\Actions\Banners\SaveBanner;
use App\Actions\Catalog\DeleteBrand;
use App\Actions\Catalog\DeleteCategory;
use App\Actions\Catalog\UpdateCatalogImage;
use App\Actions\Catalog\UpdateProductImages;
use App\Actions\News\DeleteNewsPost;
use App\Actions\Pages\DeletePage;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Support\Media\MediaReplacer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * @return array<string, mixed>
 */
function cleanupBannerData(): array
{
    return [
        'placement' => 'home_hero',
        'category_id' => null,
        'title' => ['uz' => 'Servis markazi'],
        'text' => [],
        'button_label' => [],
        'link' => '/catalog',
        'starts_at' => null,
        'ends_at' => null,
        'is_active' => true,
    ];
}

function cleanupImage(string $name = 'a.jpg'): UploadedFile
{
    return UploadedFile::fake()->image($name, Banner::DESKTOP_WIDTH, Banner::DESKTOP_HEIGHT);
}

function cleanupFiles(): int
{
    return count(Storage::disk('local')->allFiles()) + count(Storage::disk('public')->allFiles());
}

it('keeps the old banner image when a later image check fails and leaves no new file', function () {
    $banner = Banner::factory()->withImages()->create();
    $old = $banner->getFirstMedia(Banner::DESKTOP);
    $oldPath = $old->getPathRelativeToRoot();
    $filesBefore = cleanupFiles();

    $bad = UploadedFile::fake()->create('mobile.pdf', 10, 'application/pdf');

    expect(fn () => app(SaveBanner::class)->handle($banner, cleanupBannerData(), ['desktop' => cleanupImage('new.jpg'), 'mobile' => $bad]))
        ->toThrow(ValidationException::class);

    expect(Media::query()->where('model_id', $banner->id)->pluck('id')->all())->toBe([$old->id]);
    Storage::disk('local')->assertExists($oldPath);
    expect(cleanupFiles())->toBe($filesBefore);
});

it('replaces an image leaving exactly one row, deletes old files after commit and spares other models', function () {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    $otherMedia = $other->addMedia(cleanupImage('other.jpg'))->toMediaCollection($other->imageCollection());
    $first = $category->addMedia(cleanupImage())->toMediaCollection($category->imageCollection());
    $oldPath = $first->getPathRelativeToRoot();

    DB::transaction(function () use ($category, $oldPath) {
        app(UpdateCatalogImage::class)->handle($category, cleanupImage('new.jpg'), []);

        Storage::disk('local')->assertExists($oldPath);
    });

    Storage::disk('local')->assertMissing($oldPath);
    $media = Media::query()->where('model_type', $category->getMorphClass())->where('model_id', $category->id)->get();
    expect($media)->toHaveCount(1)
        ->and($media->first()->id)->not->toBe($first->id)
        ->and($category->fresh()->imageMedia()->id)->toBe($media->first()->id);
    Storage::disk('local')->assertExists($otherMedia->getPathRelativeToRoot());
    expect(Media::query()->whereKey($otherMedia->id)->exists())->toBeTrue();
});

it('removes an image only after commit', function () {
    $brand = Brand::factory()->create();
    $media = $brand->addMedia(cleanupImage())->toMediaCollection($brand->imageCollection());
    $path = $media->getPathRelativeToRoot();

    DB::transaction(function () use ($brand, $path) {
        app(UpdateCatalogImage::class)->handle($brand, null, [], remove: true);
        Storage::disk('local')->assertExists($path);
    });

    Storage::disk('local')->assertMissing($path);
    expect(Media::query()->count())->toBe(0);
});

it('deletes product images and every media owner delete action removes files after commit', function (Closure $make, Closure $delete) {
    $model = $make();
    $collection = $model instanceof Banner ? Banner::DESKTOP : $model->imageCollection();
    $media = $model->addMedia(cleanupImage())->toMediaCollection($collection);
    $path = $media->getPathRelativeToRoot();

    DB::transaction(function () use ($delete, $model, $path) {
        $delete($model);
        Storage::disk('local')->assertExists($path);
    });

    Storage::disk('local')->assertMissing($path);
    expect(Media::query()->count())->toBe(0)->and($model->fresh())->toBeNull();
})->with([
    'banner' => [fn () => Banner::factory()->create(), fn ($m) => app(DeleteBanner::class)->handle($m)],
    'brand' => [fn () => Brand::factory()->create(), fn ($m) => app(DeleteBrand::class)->handle($m)],
    'category' => [fn () => Category::factory()->create(), fn ($m) => app(DeleteCategory::class)->handle($m)],
    'page' => [fn () => Page::factory()->create(['is_system' => false]), fn ($m) => app(DeletePage::class)->handle($m)],
    'news' => [fn () => NewsPost::factory()->create(), fn ($m) => app(DeleteNewsPost::class)->handle($m)],
]);

it('removes a product image file after commit', function () {
    $product = Product::factory()->create();
    $media = $product->addMedia(cleanupImage())->toMediaCollection(Product::IMAGES);
    $path = $media->getPathRelativeToRoot();

    DB::transaction(function () use ($product, $media, $path) {
        app(UpdateProductImages::class)->delete($product, $media->id);
        Storage::disk('local')->assertExists($path);
    });

    Storage::disk('local')->assertMissing($path);
    expect(Media::query()->count())->toBe(0);
});

it('blocks deleting a category that still has banners', function () {
    $category = Category::factory()->create();
    $banner = Banner::factory()->category($category)->withImages(mobile: true)->create();
    $paths = Media::query()->get()->map(fn (Media $media) => $media->getPathRelativeToRoot())->all();

    expect(fn () => app(DeleteCategory::class)->handle($category))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['category'][0])->toBe(__('admin.categories.errors.has_banners')));

    expect(Category::query()->whereKey($category->id)->exists())->toBeTrue()
        ->and(Banner::query()->whereKey($banner->id)->exists())->toBeTrue()
        ->and(Media::query()->count())->toBe(2);

    foreach ($paths as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

it('has the banned-category message in every locale', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(__('admin.categories.errors.has_banners', [], $locale))->not->toBe('admin.categories.errors.has_banners');
    }
});

it('keeps committed media when removing old files throws after the commit', function () {
    $banner = Banner::factory()->withImages()->create();
    $old = $banner->getFirstMedia(Banner::DESKTOP);

    $failing = new class extends Filesystem
    {
        public function __construct() {}

        public function removeAllFiles(Media $media): void
        {
            throw new RuntimeException('disk denied');
        }
    };
    $this->app->scoped(MediaReplacer::class, fn () => new MediaReplacer($failing));

    app(SaveBanner::class)->handle($banner, cleanupBannerData(), ['desktop' => cleanupImage('new.jpg')]);

    $media = Media::query()->where('model_id', $banner->id)->get();
    expect($media)->toHaveCount(1)->and($media->first()->id)->not->toBe($old->id);
    Storage::disk('local')->assertExists($media->first()->getPathRelativeToRoot());
});

it('does not discard committed media when a foreign after-commit callback throws', function () {
    $banner = Banner::factory()->withImages()->create();

    $this->app->bind(UpdateCatalogImage::class, fn () => new class(app(MediaReplacer::class)) extends UpdateCatalogImage
    {
        public function handle($model, $file, array $alt, bool $remove = false, string $field = 'image', ?string $collection = null): void
        {
            parent::handle($model, $file, $alt, $remove, $field, $collection);
            DB::afterCommit(fn () => throw new RuntimeException('cache flush failed'));
        }
    });

    try {
        app(SaveBanner::class)->handle($banner, cleanupBannerData(), ['desktop' => cleanupImage('new.jpg')]);
    } catch (RuntimeException) {
    }

    $media = Media::query()->where('model_id', $banner->id)->get();
    expect($media)->toHaveCount(1);
    Storage::disk('local')->assertExists($media->first()->getPathRelativeToRoot());
});

it('keeps only the new row when the collection already holds several', function () {
    $category = Category::factory()->create();
    $category->addMedia(cleanupImage('1.jpg'))->preservingOriginal()->toMediaCollection($category->imageCollection());
    $category->addMedia(cleanupImage('2.jpg'))->toMediaCollection($category->imageCollection());
    expect(Media::query()->count())->toBe(2);

    app(UpdateCatalogImage::class)->handle($category->fresh(), cleanupImage('new.jpg'), []);

    expect(Media::query()->count())->toBe(1);
});

it('rolls back a replace: old row and files stay on both disks, new files are absent', function () {
    $category = Category::factory()->create();
    $old = $category->addMedia(cleanupImage())->toMediaCollection($category->imageCollection());
    $oldFiles = [Storage::disk('local')->allFiles(), Storage::disk('public')->allFiles()];

    try {
        app(MediaReplacer::class)->guard(fn () => DB::transaction(function () use ($category) {
            app(UpdateCatalogImage::class)->handle($category, cleanupImage('new.jpg'), []);

            throw new RuntimeException('later failure');
        }));
    } catch (RuntimeException) {
    }

    expect(Media::query()->pluck('id')->all())->toBe([$old->id])
        ->and(Storage::disk('local')->allFiles())->toBe($oldFiles[0])
        ->and(Storage::disk('public')->allFiles())->toBe($oldFiles[1])
        ->and($oldFiles[1])->not->toBe([]);
});

it('restores the row and keeps files when a delete rolls back, and drops public conversions on commit', function () {
    $brand = Brand::factory()->create();
    $brand->addMedia(cleanupImage())->toMediaCollection($brand->imageCollection());
    $before = [Storage::disk('local')->allFiles(), Storage::disk('public')->allFiles()];
    expect($before[1])->not->toBe([]);

    try {
        DB::transaction(function () use ($brand) {
            app(DeleteBrand::class)->handle($brand);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Media::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe($before[0])
        ->and(Storage::disk('public')->allFiles())->toBe($before[1]);

    app(DeleteBrand::class)->handle(Brand::findOrFail($brand->id));

    expect(Storage::disk('public')->allFiles())->toBe([])->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('keeps committed media when a throwing after-commit callback was registered before the save', function () {
    $banner = Banner::factory()->withImages()->create();

    try {
        DB::transaction(function () use ($banner) {
            DB::afterCommit(fn () => throw new RuntimeException('earlier callback failed'));

            app(SaveBanner::class)->handle($banner, cleanupBannerData(), ['desktop' => cleanupImage('new.jpg')]);
        });
    } catch (RuntimeException) {
    }

    $media = Media::query()->where('model_id', $banner->id)->get();
    expect($media)->toHaveCount(1);
    Storage::disk('local')->assertExists($media->first()->getPathRelativeToRoot());
});

it('surfaces the original exception even when the discard itself throws', function () {
    $replacer = new class(app(Filesystem::class)) extends MediaReplacer
    {
        public function discardCreated(): void
        {
            throw new LogicException('discard failed');
        }
    };

    expect(fn () => $replacer->guard(fn () => throw new RuntimeException('original')))->toThrow(RuntimeException::class, 'original');
});
