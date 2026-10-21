<?php

use App\Actions\Catalog\AddProductImages;
use App\Actions\Catalog\ForceDeleteProduct;
use App\Actions\Catalog\SaveProductDocument;
use App\Enums\DocumentType;
use App\Enums\RelationType;
use App\Enums\Role;
use App\Livewire\Admin\Products\Documents;
use App\Livewire\Admin\Products\Images;
use App\Livewire\Admin\Products\Relations;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductRelation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

it('uploads several images with uuid names, private originals and public WebP conversions', function () {
    $product = Product::factory()->create(['slug' => 'zebra-ds2208']);

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Images::class, ['product' => $product])
        ->set('uploads', [UploadedFile::fake()->image('front.jpg', 800, 800), UploadedFile::fake()->image('side.png', 600, 600)])
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertSee('data-image-thumb', false);

    $media = $product->fresh()->getMedia(Product::IMAGES);

    expect($media)->toHaveCount(2)
        ->and($media[0]->disk)->toBe('local')
        ->and($media[0]->conversions_disk)->toBe('public')
        ->and($media[0]->file_name)->toMatch('/^[0-9a-f-]{36}\.jpg$/')
        ->and($media[0]->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($product->fresh()->imageUrl())->toContain('conversions/');
});

it('rejects non-images and files over 5 MB', function () {
    $product = Product::factory()->create();

    Livewire::actingAs(staffUser())
        ->test(Images::class, ['product' => $product])
        ->set('uploads', [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
        ->assertHasErrors('uploads.0');

    Livewire::actingAs(staffUser())
        ->test(Images::class, ['product' => $product])
        ->set('uploads', [UploadedFile::fake()->image('big.jpg')->size(6000)])
        ->assertHasErrors('uploads.0');

    expect($product->fresh()->getMedia(Product::IMAGES))->toHaveCount(0);
});

it('reorders images, saves alt text per locale and deletes', function () {
    $product = Product::factory()->create();
    $first = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
    $second = $product->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaCollection(Product::IMAGES);

    $component = Livewire::actingAs(staffUser())
        ->test(Images::class, ['product' => $product])
        ->call('sortImage', $second->id, 0);

    expect($product->fresh()->getMedia(Product::IMAGES)->pluck('id')->all())->toBe([$second->id, $first->id]);

    $component->set("alts.{$first->id}.ru", 'Вид сбоку')->call('saveAlt', $first->id);
    expect($first->fresh()->getCustomProperty('alt'))->toBe(['uz' => '', 'ru' => 'Вид сбоку', 'en' => '']);

    $component->call('deleteImage', $second->id);
    expect($product->fresh()->getMedia(Product::IMAGES)->pluck('id')->all())->toBe([$first->id]);
});

it('ignores media of other products', function () {
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    $foreign = $other->addMedia(UploadedFile::fake()->image('x.jpg'))->toMediaCollection(Product::IMAGES);

    Livewire::actingAs(staffUser())->test(Images::class, ['product' => $product])->call('deleteImage', $foreign->id);

    expect($foreign->fresh())->not->toBeNull();
});

it('forbids image writes without update rights on the product', function () {
    $hardware = Product::factory()->create();

    Livewire::actingAs(staffUser(Role::ServiceEngineer))
        ->test(Images::class, ['product' => $hardware])
        ->call('_startUpload', 'uploads', [['name' => 'a.jpg', 'size' => 1024, 'type' => 'image/jpeg']], true)
        ->assertForbidden();

    Livewire::actingAs(staffUser(Role::ServiceEngineer))
        ->test(Images::class, ['product' => $hardware])
        ->call('sortImage', 1, 0)
        ->assertForbidden();
});

it('adds a document file on the private disk and serves it only to staff', function () {
    $product = Product::factory()->create();

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Documents::class, ['product' => $product])
        ->call('create')
        ->set('form.title.uz', 'Texnik maʼlumot')
        ->set('form.type', 'datasheet')
        ->set('form.locale', 'ru')
        ->set('form.file', UploadedFile::fake()->create('DS2208 spec.pdf', 300, 'application/pdf'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', false);

    $document = ProductDocument::query()->sole();

    expect($document->path)->toStartWith("product-documents/{$product->id}/")
        ->and($document->path)->toMatch('/[0-9a-f-]{36}\.pdf$/')
        ->and($document->url)->toBeNull()
        ->and($document->original_name)->toBe('DS2208 spec.pdf')
        ->and($document->locale)->toBe('ru')
        ->and($document->type)->toBe(DocumentType::Datasheet);
    Storage::disk('local')->assertExists($document->path);
    Storage::disk('public')->assertMissing($document->path);

    auth()->guard('web')->logout();
    $this->get(route('admin.products.documents.download', $document))->assertRedirect(route('admin.login'));
    $this->actingAs(staffUser(Role::SalesManager))
        ->get(route('admin.products.documents.download', $document))
        ->assertOk()
        ->assertDownload('DS2208 spec.pdf');
});

it('adds an https link document and switches a file to a link', function () {
    $product = Product::factory()->create();
    $path = UploadedFile::fake()->create('a.zip', 10, 'application/zip')->store("product-documents/{$product->id}", 'local');
    $document = ProductDocument::factory()->for($product)->create(['path' => $path, 'url' => null, 'type' => DocumentType::Driver]);

    $component = Livewire::actingAs(staffUser())->test(Documents::class, ['product' => $product]);

    $component->call('create')
        ->set('form.title.uz', 'Qoʻllanma')
        ->set('form.source', 'url')
        ->set('form.url', 'http://example.com/manual.pdf')
        ->call('save')
        ->assertHasErrors('form.url');

    $component->set('form.url', 'https://example.com/manual.pdf')->call('save')->assertHasNoErrors();

    $component->call('edit', $document->id)
        ->set('form.source', 'url')
        ->set('form.url', 'https://example.com/driver.zip')
        ->call('save')
        ->assertHasNoErrors();

    expect($document->fresh())->path->toBeNull()->url->toBe('https://example.com/driver.zip')
        ->and(ProductDocument::query()->where('product_id', $product->id)->count())->toBe(2);
    Storage::disk('local')->assertMissing($path);
});

it('rejects other file types, oversized files and a missing source', function () {
    $product = Product::factory()->create();
    $component = Livewire::actingAs(staffUser())->test(Documents::class, ['product' => $product])->call('create')->set('form.title.uz', 'Fayl');

    $component->call('save')->assertHasErrors('form.file');
    $component->set('form.file', UploadedFile::fake()->create('x.docx', 10))->call('save')->assertHasErrors('form.file');
    $component->set('form.file', UploadedFile::fake()->create('big.pdf', 21000, 'application/pdf'))->call('save')->assertHasErrors('form.file');

    expect(ProductDocument::query()->count())->toBe(0);
});

it('deletes a document with its file', function () {
    $product = Product::factory()->create();
    $path = UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')->store("product-documents/{$product->id}", 'local');
    $document = ProductDocument::factory()->for($product)->create(['path' => $path, 'url' => null]);

    Livewire::actingAs(staffUser())->test(Documents::class, ['product' => $product])->call('delete', $document->id)->assertDispatched('toast');

    expect(ProductDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('forbids document writes to a service engineer on hardware', function () {
    $product = Product::factory()->create();
    $document = ProductDocument::factory()->for($product)->create();

    Livewire::actingAs(staffUser(Role::ServiceEngineer))->test(Documents::class, ['product' => $product])->call('delete', $document->id)->assertForbidden();

    expect($document->fresh())->not->toBeNull();
});

it('links related products by search and type, without duplicates or self links', function () {
    $printer = Product::factory()->create(['sku' => 'TSC-TE200']);
    $labels = Product::factory()->consumable()->create(['sku' => 'LBL-5840', 'name' => ['uz' => 'Termoetiketka 58×40']]);

    $component = Livewire::actingAs(staffUser())
        ->test(Relations::class, ['product' => $printer])
        ->set('type', 'consumable')
        ->set('search', 'termoetiketka')
        ->assertSee('LBL-5840')
        ->assertDontSee('data-relation="TSC-TE200"', false)
        ->call('add', $labels->id)
        ->assertDispatched('toast')
        ->assertSee('data-relation="LBL-5840"', false);

    $component->call('add', $labels->id)->assertDispatched('toast', type: 'error');
    $component->call('add', $printer->id)->assertDispatched('toast', type: 'error');

    $relation = ProductRelation::query()->sole();
    expect($relation->related_id)->toBe($labels->id)->and($relation->type)->toBe(RelationType::Consumable);

    $component->call('remove', $relation->id);
    expect(ProductRelation::query()->count())->toBe(0);
});

it('refuses uploads from users who may not edit the product', function (string $component, string $property, string $type) {
    $product = Product::factory()->service()->create();
    $file = [['name' => 'a.bin', 'size' => 1024, 'type' => $type]];

    // The calls Livewire's JavaScript makes before and after sending the file.
    Livewire::actingAs(staffUser(Role::SalesManager))
        ->test($component, ['product' => $product])
        ->call('_startUpload', $property, $file, $component === Images::class)
        ->assertForbidden();

    Livewire::actingAs(staffUser(Role::SalesManager))
        ->test($component, ['product' => $product])
        ->call('_finishUpload', $property, ['tmp.bin'], $component === Images::class)
        ->assertForbidden();

    expect($product->fresh()->getMedia(Product::IMAGES))->toHaveCount(0);
})->with([
    'images' => [Images::class, 'uploads', 'image/jpeg'],
    'documents' => [Documents::class, 'form.file', 'application/pdf'],
]);

it('stores no image when one file of the batch is not an image', function () {
    $product = Product::factory()->create();

    expect(fn () => app(AddProductImages::class)->handle($product, [
        UploadedFile::fake()->image('ok.jpg'),
        UploadedFile::fake()->create('fake.png', 10, 'application/pdf'),
    ]))->toThrow(ValidationException::class);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('removes the files already written when storing a later image fails', function () {
    $product = Product::factory()->create();
    $files = [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')];
    $calls = 0;

    Media::creating(function () use (&$calls): void {
        if (++$calls === 2) {
            throw new RuntimeException('disk full');
        }
    });

    expect(fn () => app(AddProductImages::class)->handle($product, $files))->toThrow(RuntimeException::class);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('purges image files only after the permanent delete commits', function () {
    $product = Product::factory()->create();
    $media = $product->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaCollection(Product::IMAGES);
    $original = $media->getPathRelativeToRoot();
    $product->delete();

    Storage::disk('local')->assertExists($original);

    app(ForceDeleteProduct::class)->handle(Product::onlyTrashed()->findOrFail($product->id));

    expect(Media::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($original);
});

it('blocks relation and document writes for a service engineer on hardware', function () {
    $hardware = Product::factory()->create();
    $other = Product::factory()->create();
    $relation = ProductRelation::factory()->create(['product_id' => $hardware->id, 'related_id' => $other->id]);
    $document = ProductDocument::factory()->for($hardware)->create();
    $user = staffUser(Role::ServiceEngineer);

    Livewire::actingAs($user)->test(Relations::class, ['product' => $hardware])->call('add', $other->id)->assertForbidden();
    Livewire::actingAs($user)->test(Relations::class, ['product' => $hardware])->call('remove', $relation->id)->assertForbidden();
    Livewire::actingAs($user)->test(Documents::class, ['product' => $hardware])->call('save')->assertForbidden();
    Livewire::actingAs($user)->test(Documents::class, ['product' => $hardware])->call('delete', $document->id)->assertForbidden();

    expect(ProductRelation::query()->count())->toBe(1)
        ->and($document->fresh())->not->toBeNull();
});

it('keeps non-staff users away from document downloads', function () {
    $document = ProductDocument::factory()->file()->create();
    Storage::disk('local')->put($document->path, 'pdf');

    $response = $this->actingAs(User::factory()->create())->get(route('admin.products.documents.download', $document));

    expect($response->status())->toBeIn([302, 403]);
    expect($response->status() === 302 ? $response->headers->get('Location') : route('admin.login'))->toBe(route('admin.login'));
});

it('sends documents with nosniff', function () {
    $document = ProductDocument::factory()->file()->create();
    Storage::disk('local')->put($document->path, 'pdf');

    $this->actingAs(staffUser())
        ->get(route('admin.products.documents.download', $document))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('stores the SHA-256 of an uploaded document', function () {
    $product = Product::factory()->create();
    $file = UploadedFile::fake()->createWithContent('spec.pdf', '%PDF-1.4 test');

    $document = app(SaveProductDocument::class)->handle($product, null, [
        'title' => ['uz' => 'Hujjat'], 'type' => DocumentType::Datasheet, 'locale' => null, 'url' => null,
    ], $file, staffUser(Role::ContentManager));

    expect($document->sha256)->toBe(hash('sha256', '%PDF-1.4 test'));

    $document = app(SaveProductDocument::class)->handle($product, $document, [
        'title' => ['uz' => 'Hujjat'], 'type' => DocumentType::Datasheet, 'locale' => null, 'url' => 'https://example.com/a.pdf',
    ]);

    expect($document->sha256)->toBeNull();
});

it('lets only admins upload executable documents', function (Role $role, bool $allowed) {
    $product = Product::factory()->create();
    $upload = fn () => app(SaveProductDocument::class)->handle($product, null, [
        'title' => ['uz' => 'Drayver'], 'type' => DocumentType::Driver, 'locale' => null, 'url' => null,
    ], UploadedFile::fake()->createWithContent('driver.exe', 'MZ driver'), staffUser($role));

    if ($allowed) {
        expect($upload()->sha256)->toBe(hash('sha256', 'MZ driver'));

        return;
    }

    expect($upload)->toThrow(ValidationException::class, __('admin.products.documents.errors.executable_admin_only'));
    expect(ProductDocument::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'admin' => [Role::Admin, true],
    'content manager' => [Role::ContentManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);
