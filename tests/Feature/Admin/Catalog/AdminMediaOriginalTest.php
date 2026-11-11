<?php

use App\Actions\Catalog\AddProductImages;
use App\Actions\Catalog\UpdateCatalogImage;
use App\Enums\Role;
use App\Livewire\Admin\Banners\Form as BannerForm;
use App\Livewire\Admin\Brands\Form as BrandForm;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\News\Form as NewsForm;
use App\Livewire\Admin\Pages\Form as PageForm;
use App\Livewire\Admin\Products\Images;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Media\ImageLimits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * A valid JPEG header followed by junk: passes the content-type check, fails to decode.
 */
function corruptJpeg(): string
{
    $image = imagecreatetruecolor(200, 200);
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();

    return substr($bytes, 0, 160).str_repeat("\x13\x37", 200);
}

it('serves an image original to a user who may view the owner, with safe headers', function (Role $role) {
    $product = Product::factory()->create();
    $media = $product->addMedia(UploadedFile::fake()->image('a.jpg', 300, 300))->toMediaCollection(Product::IMAGES);

    $response = $this->actingAs(staffUser($role))->get(route('admin.media.original', $media))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
})->with([
    'admin' => [Role::Admin],
    'content manager' => [Role::ContentManager],
    'sales manager' => [Role::SalesManager],
]);

it('serves originals of every allowed owner and collection', function (callable $make) {
    $media = $make();

    $this->actingAs(staffUser())->get(route('admin.media.original', $media))->assertOk();
})->with([
    'brand logo' => [fn () => Brand::factory()->create()->addMedia(UploadedFile::fake()->image('a.png'))->toMediaCollection('logo')],
    'category image' => [fn () => Category::factory()->create()->addMedia(UploadedFile::fake()->image('a.png'))->toMediaCollection('image')],
    'page cover' => [fn () => Page::factory()->create()->addMedia(UploadedFile::fake()->image('a.png'))->toMediaCollection(Page::factory()->make()->imageCollection())],
    'news cover' => [fn () => NewsPost::factory()->create()->addMedia(UploadedFile::fake()->image('a.png'))->toMediaCollection(NewsPost::factory()->make()->imageCollection())],
    'banner desktop' => [fn () => Banner::factory()->create()->addMedia(UploadedFile::fake()->image('a.png', 1300, 400))->toMediaCollection(Banner::DESKTOP)],
    'banner mobile' => [fn () => Banner::factory()->create()->addMedia(UploadedFile::fake()->image('a.png', 700, 500))->toMediaCollection(Banner::MOBILE)],
    'company logo' => [fn () => Setting::query()->create(['key' => Setting::LOGO, 'group' => 'seo', 'value' => null])->addMedia(UploadedFile::fake()->image('a.png', 200, 200))->toMediaCollection(Setting::LOGO)],
]);

it('refuses a role without permission on the owner', function () {
    $setting = Setting::query()->create(['key' => Setting::LOGO, 'group' => 'seo', 'value' => null]);
    $logo = $setting->addMedia(UploadedFile::fake()->image('a.png', 200, 200))->toMediaCollection(Setting::LOGO);
    $banner = Banner::factory()->create()->addMedia(UploadedFile::fake()->image('b.png', 1300, 400))->toMediaCollection(Banner::DESKTOP);

    $contentManager = staffUser(Role::ContentManager);
    $salesManager = staffUser(Role::SalesManager);

    $this->actingAs($contentManager)->get(route('admin.media.original', $logo))->assertForbidden();
    $this->actingAs($salesManager)->get(route('admin.media.original', $banner))->assertForbidden();
});

it('returns 404 for stamp, signature, UzQR and any other collection', function () {
    $setting = Setting::query()->create(['key' => Setting::STAMP, 'group' => 'legal', 'value' => null]);
    $stamp = $setting->addMedia(UploadedFile::fake()->image('s.png', 200, 200))->toMediaCollection(Setting::PRIVATE_MEDIA[Setting::STAMP]['collection']);
    $signature = $setting->addMedia(UploadedFile::fake()->image('g.png', 200, 200))->toMediaCollection(Setting::PRIVATE_MEDIA[Setting::SIGNATURE]['collection']);
    $uzqr = $setting->addMedia(UploadedFile::fake()->image('q.png', 200, 200))->toMediaCollection(Setting::UZQR_HISTORY);
    $other = Product::factory()->create()->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
    $other->forceFill(['collection_name' => 'documents'])->save();

    $admin = staffUser();

    foreach ([$stamp, $signature, $uzqr, $other] as $media) {
        $this->actingAs($admin)->get(route('admin.media.original', $media))->assertNotFound();
    }
});

it('redirects a guest to the login page', function () {
    $media = Product::factory()->create()->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);

    $this->get(route('admin.media.original', $media))->assertRedirect(route('admin.login'));
});

it('rejects a 4001 px image on every image form', function () {
    $big = fn () => UploadedFile::fake()->image('big.jpg', 4001, 1300);
    $admin = staffUser();

    Livewire::actingAs($admin)->test(BrandForm::class)->set('form.name', 'Brend')->set('form.logo', $big())->call('save')->assertHasErrors(['form.logo']);
    Livewire::actingAs($admin)->test(CategoryForm::class)->set('form.name.uz', 'Toifa')->set('form.image', $big())->call('save')->assertHasErrors(['form.image']);
    Livewire::actingAs($admin)->test(PageForm::class)->set('form.title.uz', 'Sahifa')->set('form.image', $big())->call('save')->assertHasErrors(['form.image']);
    Livewire::actingAs($admin)->test(NewsForm::class)->set('form.title.uz', 'Yangilik')->set('form.image', $big())->call('save')->assertHasErrors(['form.image']);
    Livewire::actingAs($admin)->test(BannerForm::class)->set('form.desktop', $big())->call('save')->assertHasErrors(['form.desktop']);
    Livewire::actingAs($admin)->test(BannerForm::class)->set('form.mobile', UploadedFile::fake()->image('m.jpg', 4001, 900))->call('save')->assertHasErrors(['form.mobile']);

    $product = Product::factory()->create();
    Livewire::actingAs($admin)->test(Images::class, ['product' => $product])->set('uploads', [$big()])->assertHasErrors(['uploads.0']);
    expect($product->fresh()->getMedia(Product::IMAGES))->toBeEmpty();
});

it('limits a gallery batch to the shared batch size', function () {
    $product = Product::factory()->create();

    Livewire::actingAs(staffUser())
        ->test(Images::class, ['product' => $product])
        ->set('uploads', array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg", 100, 100), range(1, ImageLimits::MAX_BATCH + 1)))
        ->assertHasErrors(['uploads']);

    expect($product->fresh()->getMedia(Product::IMAGES))->toBeEmpty();
});

it('creates conversions synchronously even when the queue is faked', function () {
    Queue::fake();
    $product = Product::factory()->create();

    app(AddProductImages::class)->handle($product, [UploadedFile::fake()->image('a.jpg', 900, 900)]);

    $media = $product->fresh()->getFirstMedia(Product::IMAGES);

    expect($media->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($media->hasGeneratedConversion('large'))->toBeTrue();
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot('thumb'));
    Queue::assertNothingPushed();
});

it('falls back to the authorized original route in admin previews when a conversion is missing', function () {
    $product = Product::factory()->create();
    $media = withoutConversions($product->addMedia(UploadedFile::fake()->image('a.jpg', 300, 300))->toMediaCollection(Product::IMAGES));
    $url = route('admin.media.original', $media);

    expect($product->fresh()->adminImageUrl())->toBe($url);

    Livewire::actingAs(staffUser())->test(Images::class, ['product' => $product])->assertSeeHtml($url);

    $brand = Brand::factory()->create();
    $logo = withoutConversions($brand->addMedia(UploadedFile::fake()->image('a.png', 300, 300))->toMediaCollection('logo'));

    Livewire::actingAs(staffUser())->test(BrandForm::class, ['brand' => $brand])
        ->assertSeeHtml(route('admin.media.original', $logo))
        ->assertDontSeeHtml('data-image-poll');
});

it('uses the conversion URL in admin previews when it exists', function () {
    $product = Product::factory()->create();
    $product->addMedia(UploadedFile::fake()->image('a.jpg', 300, 300))->toMediaCollection(Product::IMAGES);

    expect($product->fresh()->adminImageUrl())->toContain('conversions/')->not->toContain('/admin/media');
});

it('keeps public surfaces free of original and admin media URLs without conversions', function () {
    $product = Product::factory()->create(['is_active' => true, 'slug' => 'no-conv']);
    $media = withoutConversions($product->addMedia(UploadedFile::fake()->image('a.jpg', 300, 300))->toMediaCollection(Product::IMAGES));

    expect($product->fresh()->imageUrl())->toBeNull();

    $pages = [
        $this->get('/en/product/no-conv')->getContent(),
        $this->get('/sitemap.xml')->getContent(),
    ];

    foreach ($pages as $html) {
        expect($html)->not->toContain('/admin/media')->not->toContain($media->file_name);
    }
});

it('rolls a gallery batch back with a localized error when a conversion fails', function () {
    $product = Product::factory()->create();
    $good = UploadedFile::fake()->image('ok.jpg', 400, 400);
    $broken = UploadedFile::fake()->createWithContent('bad.jpg', corruptJpeg());

    expect(fn () => app(AddProductImages::class)->handle($product, [$good, $broken]))->toThrow(ValidationException::class);

    expect($product->fresh()->getMedia(Product::IMAGES))->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('shows the localized error and keeps the old image when a replacement cannot be converted', function () {
    $brand = Brand::factory()->create();
    app(UpdateCatalogImage::class)->handle($brand, UploadedFile::fake()->image('old.png', 300, 300), [], field: 'logo');
    $old = $brand->fresh()->getFirstMedia('logo');

    expect(fn () => app(UpdateCatalogImage::class)->handle($brand, UploadedFile::fake()->createWithContent('bad.jpg', corruptJpeg()), [], field: 'logo'))
        ->toThrow(ValidationException::class);

    expect($brand->fresh()->media()->where('collection_name', 'logo')->pluck('id')->all())->toBe([$old->id]);
    Storage::disk('local')->assertExists($old->getPathRelativeToRoot());
    Storage::disk('public')->assertExists($old->getPathRelativeToRoot('thumb'));
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('leaves no rows or files when the batch fails at index 1 of 3', function () {
    $product = Product::factory()->create();
    $files = [UploadedFile::fake()->image('a.jpg', 300, 300), UploadedFile::fake()->createWithContent('bad.jpg', corruptJpeg()), UploadedFile::fake()->image('c.jpg', 300, 300)];

    try {
        app(AddProductImages::class)->handle($product, $files);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('uploads.1');
    }

    expect($product->fresh()->getMedia(Product::IMAGES))->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('enforces the pixel cap inside the actions too', function () {
    $product = Product::factory()->create();
    $brand = Brand::factory()->create();

    expect(fn () => app(AddProductImages::class)->handle($product, [UploadedFile::fake()->image('big.jpg', 4001, 100)]))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateCatalogImage::class)->handle($brand, UploadedFile::fake()->image('big.png', 100, 4001), [], field: 'logo'))->toThrow(ValidationException::class);

    expect(Media::query()->count())->toBe(0);
});

it('has the conversion_failed message in every locale', function (string $locale) {
    expect(__('admin.image_upload.conversion_failed', [], $locale))->not->toBe('admin.image_upload.conversion_failed');
})->with(['uz', 'ru', 'en']);
