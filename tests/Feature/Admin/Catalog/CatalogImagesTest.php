<?php

use App\Actions\Catalog\UpdateCatalogImage;
use App\Livewire\Admin\Brands\Form as BrandForm;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

it('stores an upload under a random name with the extension from its content', function () {
    $category = Category::factory()->create();

    Livewire::actingAs(staffUser())
        ->test(CategoryForm::class, ['category' => $category])
        ->set('form.image', UploadedFile::fake()->image('x.php.jpg', 800, 600))
        ->call('save')
        ->assertHasNoErrors();

    $media = $category->fresh()->getFirstMedia('image');

    expect($media)->not->toBeNull()
        ->and($media->file_name)->toMatch('/^[0-9a-f-]{36}\.jpg$/')
        ->and($media->file_name)->not->toContain('php')
        ->and($media->disk)->toBe('local')
        ->and($media->conversions_disk)->toBe('public');

    // The original (which may carry EXIF) stays private; only conversions are public.
    Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
    Storage::disk('public')->assertMissing($media->getPathRelativeToRoot());
    Storage::disk('public')->assertExists($media->getPathRelativeToRoot('thumb'));
    expect($category->fresh()->imageUrl())->toContain('conversions/')->toEndWith('.webp');
});

it('renders only conversions, never the original', function () {
    Queue::fake();
    $brand = Brand::factory()->create();
    $brand->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection('logo');

    expect($brand->fresh()->imageUrl())->toBeNull()
        ->and($brand->fresh()->imageProcessing())->toBeTrue();
});

it('turns a rejected file into a validation error', function () {
    $brand = Brand::factory()->create();

    expect(fn () => app(UpdateCatalogImage::class)->handle($brand, UploadedFile::fake()->create('notes.txt', 1, 'text/plain'), [], field: 'logo'))
        ->toThrow(ValidationException::class);

    expect($brand->fresh()->getFirstMedia('logo'))->toBeNull();
});

it('saves nothing when the image step fails', function () {
    $this->mock(UpdateCatalogImage::class)
        ->shouldReceive('handle')
        ->andThrow(ValidationException::withMessages(['image' => 'Rejected']));

    Livewire::actingAs(staffUser())
        ->test(CategoryForm::class)
        ->set('form.name.uz', 'Rasmli toifa')
        ->set('form.image', UploadedFile::fake()->image('a.jpg'))
        ->call('save')
        ->assertHasErrors(['form.image'])
        ->assertNoRedirect();

    expect(Category::query()->count())->toBe(0);
});

it('rolls back a brand when its logo fails', function () {
    $this->mock(UpdateCatalogImage::class)
        ->shouldReceive('handle')
        ->andThrow(ValidationException::withMessages(['logo' => 'Rejected']));

    Livewire::actingAs(staffUser())
        ->test(BrandForm::class)
        ->set('form.name', 'Sunmi')
        ->set('form.logo', UploadedFile::fake()->image('a.png'))
        ->call('save')
        ->assertHasErrors(['form.logo']);

    expect(Brand::query()->count())->toBe(0);
});
