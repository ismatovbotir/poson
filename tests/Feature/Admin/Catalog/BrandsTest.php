<?php

use App\Actions\Catalog\SaveBrand;
use App\Enums\Role;
use App\Livewire\Admin\Brands\Form;
use App\Livewire\Admin\Brands\Index;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\Pages\Form as PageForm;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use Database\Factories\CategoryFactory;
use Database\Factories\PageFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('lists brands with logo thumbnails without lazy loading', function () {
    Storage::fake('public');
    Storage::fake('local');
    $zebra = Brand::factory()->create(['name' => 'Zebra']);
    $zebra->addMedia(UploadedFile::fake()->image('zebra.png', 600, 300))->toMediaCollection('logo');
    Brand::factory()->count(2)->create();

    $this->actingAs(staffUser())
        ->get(route('admin.brands.index'))
        ->assertOk()
        ->assertSeeLivewire(Index::class)
        ->assertSee('Zebra')
        ->assertSee('data-brand-logo', false)
        ->assertSee('conversions/', false);
});

it('creates a brand with an auto slug, description and logo', function () {
    Storage::fake('public');
    Storage::fake('local');

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class)
        ->set('form.name', 'Honeywell')
        ->set('form.website', 'https://www.honeywell.com')
        ->set('form.description.ru', '<p>Сканеры <a href="javascript:alert(1)" onclick="x()">ссылка</a></p>')
        ->set('form.logo', UploadedFile::fake()->image('logo.png', 400, 200))
        ->set('form.logo_alt.uz', 'Honeywell logotipi')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $brand = Brand::query()->sole();

    expect($brand->slug)->toBe('honeywell')
        ->and($brand->website)->toBe('https://www.honeywell.com')
        ->and($brand->getTranslation('description', 'ru'))->toBe('<p>Сканеры <a>ссылка</a></p>')
        ->and($brand->getFirstMedia('logo'))->not->toBeNull()
        ->and($brand->imageAlt('uz'))->toBe('Honeywell logotipi');
});

it('accepts only https websites', function () {
    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.name', 'Zebra 2')
        ->set('form.website', 'http://zebra.com')
        ->call('save')
        ->assertHasErrors(['form.website']);
});

it('generates a unique slug from the name and ignores a slug sent by the client', function () {
    Brand::factory()->create(['slug' => 'zebra']);

    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->assertDontSeeHtml('wire:model="form.slug"')
        ->set('form.name', 'Zebra')
        ->assertSeeHtml('data-slug-value>zebra</span>')
        ->set('form.slug', 'forged')
        ->call('save')
        ->assertHasNoErrors();

    expect(Brand::query()->latest('id')->first()->slug)->toBe('zebra-2')
        ->and(Brand::query()->where('slug', 'forged')->exists())->toBeFalse();
});

it('regenerates the slug once when a brand with the same name is created concurrently', function () {
    $clashed = false;

    Brand::creating(function (Brand $brand) use (&$clashed): void {
        if (! $clashed) {
            $clashed = true;
            Brand::factory()->create(['name' => 'Datalogic', 'slug' => $brand->slug]);
        }
    });

    $brand = app(SaveBrand::class)->handle(null, ['name' => 'Datalogic', 'description' => [], 'website' => null, 'is_active' => true, 'sort' => null]);

    expect($brand->slug)->toBe('datalogic-2')
        ->and(Brand::query()->count())->toBe(2);
});

it('keeps the slug of an existing brand on rename and tampering', function () {
    $brand = Brand::factory()->create(['name' => 'TSC', 'slug' => 'tsc']);

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['brand' => $brand])
        ->assertSeeHtml('data-slug-locked')
        ->set('form.name', 'TSC Printronix')
        ->set('form.slug', 'forged')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('form.slug', 'tsc');

    expect($brand->fresh())->name->toBe('TSC Printronix')->slug->toBe('tsc');
});

it('updates and deletes a brand', function () {
    $brand = Brand::factory()->create(['name' => 'TSC']);

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['brand' => $brand])
        ->set('form.is_active', false)
        ->set('form.sort', '5')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect($brand->fresh())->is_active->toBeFalse()->sort->toBe(5);

    Livewire::actingAs(staffUser())
        ->test(Index::class)
        ->call('delete', $brand->id)
        ->assertDispatched('toast');

    expect(Brand::query()->count())->toBe(0);
});

it('lets every staff role view brands', function (Role $role) {
    $brand = Brand::factory()->create();
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.brands.index'))->assertOk();
    $this->actingAs($user)->get(route('admin.brands.edit', $brand))->assertOk();
})->with([...catalogWriterRoles(), ...catalogViewOnlyRoles()]);

it('forbids brand writes for view-only roles', function (Role $role) {
    $brand = Brand::factory()->create(['name' => 'Godex']);
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.brands.create'))->assertForbidden();

    Livewire::actingAs($user)->test(Form::class, ['brand' => $brand])
        ->set('form.name', 'Changed')
        ->call('save')
        ->assertForbidden();

    Livewire::actingAs($user)->test(Index::class)->call('delete', $brand->id)->assertForbidden();

    expect($brand->fresh()->name)->toBe('Godex');
})->with(catalogViewOnlyRoles());

it('refreshes the search text of its products, trashed ones too, when renamed', function () {
    $brand = Brand::factory()->create(['name' => 'Metrologic']);
    $product = Product::factory()->for($brand)->create(['sku' => 'MS-9540']);
    $trashed = Product::factory()->for($brand)->create();
    $trashed->delete();
    $other = Product::factory()->create();
    $otherText = $other->fresh()->search_text;

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['brand' => $brand])
        ->set('form.name', 'Honeywell Metrologic')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->search_text)->toContain('honeywell metrologic')->toContain('ms-9540')
        ->and(Product::withTrashed()->find($trashed->id)->search_text)->toContain('honeywell metrologic')
        ->and($other->fresh()->search_text)->toBe($otherText);
});

it('rejects low-price wording in the description, in the form and in SaveBrand', function () {
    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class)
        ->set('form.name', 'Zebra')
        ->set('form.description.ru', '<p>Самые большие скидки</p>')
        ->set('form.description.en', '<p>Cheapest scanners</p>')
        ->call('save')
        ->assertHasErrors(['form.description.ru', 'form.description.en']);

    expect(fn () => app(SaveBrand::class)->handle(null, [
        'name' => 'Zebra',
        'slug' => null,
        'description' => ['uz' => '<p>Eng <strong>arzon</strong> narx</p>', 'ru' => null, 'en' => null],
        'website' => null,
        'is_active' => true,
        'sort' => null,
    ]))->toThrow(ValidationException::class)
        ->and(Brand::query()->count())->toBe(0);
});

it('shows the chosen logo as a preview in the form before saving', function () {
    Storage::fake('public');
    Storage::fake('local');
    $brand = Brand::factory()->create();

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class, ['brand' => $brand])
        ->set('form.logo', UploadedFile::fake()->image('new.png', 400, 200))
        ->assertHasNoErrors()
        ->assertSeeHtml('data-image-preview');
});

it('shows the new logo in the form right after replacing it, without polling', function () {
    Storage::fake('public');
    Storage::fake('local');
    $brand = Brand::factory()->create();
    $brand->addMedia(UploadedFile::fake()->image('old.png', 600, 300))->toMediaCollection('logo');

    // Production has no queue worker: conversions run during the save anyway.
    Queue::fake();

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class, ['brand' => $brand->fresh()])
        ->set('form.logo', UploadedFile::fake()->image('new.png', 400, 200))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-image-preview')
        ->assertDontSeeHtml('data-image-poll')
        ->assertDontSeeHtml('data-image-processing');

    expect($brand->fresh()->imageUrl())->toContain('conversions/');
});

it('previews the original through the authorized route while a logo has no conversion', function () {
    Storage::fake('public');
    Storage::fake('local');
    $brand = Brand::factory()->create();
    $media = withoutConversions($brand->addMedia(UploadedFile::fake()->image('new.png', 400, 200))->toMediaCollection('logo'));

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class, ['brand' => $brand->fresh()])
        ->assertSeeHtml('data-image-preview')
        ->assertSeeHtml(route('admin.media.original', $media))
        ->assertDontSeeHtml('data-image-poll');
});

it('keeps unsaved form input while the processing poll re-renders', function () {
    Storage::fake('public');
    Storage::fake('local');
    $brand = Brand::factory()->create(['name' => 'Zebra']);
    Queue::fake();
    $brand->addMedia(UploadedFile::fake()->image('new.png', 400, 200))->toMediaCollection('logo');

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class, ['brand' => $brand->fresh()])
        ->set('form.name', 'Zebra Technologies')
        ->set('form.logo_alt.uz', 'Yangi logotip')
        ->call('$refresh')
        ->assertSet('form.name', 'Zebra Technologies')
        ->assertSet('form.logo_alt.uz', 'Yangi logotip');

    expect($brand->fresh()->name)->toBe('Zebra');
});

it('forbids the brand form without permission', function () {
    $brand = Brand::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(Form::class, ['brand' => $brand])
        ->assertForbidden();
});

it('renders the processing poll in the shared image upload component', function () {
    view()->share('errors', new ViewErrorBag);
    $html = Blade::render('<x-admin.image-upload model="form.image" :processing="true" />');

    expect($html)->toContain('wire:poll.3s.visible="$refresh"')
        ->toContain('data-image-poll')
        ->toContain('data-image-processing-hint')
        ->toContain('x-on:toast.window="start()"'); // a save restarts the 60 s window

    $ready = Blade::render('<x-admin.image-upload model="form.image" current-url="https://x.test/a.webp" :processing="true" />');

    expect($ready)->not->toContain('data-image-poll')->toContain('data-image-preview');
});

it('previews the original while the image of another real form has no conversion', function (string $factory, string $form, string $param, string $collection) {
    Storage::fake('public');
    Storage::fake('local');
    $record = $factory::new()->create();
    $media = withoutConversions($record->addMedia(UploadedFile::fake()->image('new.png', 400, 200))->toMediaCollection($collection));

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test($form, [$param => $record->fresh()])
        ->assertSeeHtml('data-image-preview')
        ->assertSeeHtml(route('admin.media.original', $media))
        ->assertDontSeeHtml('data-image-poll');
})->with([
    'category' => [CategoryFactory::class, CategoryForm::class, 'category', 'image'],
    'page' => [PageFactory::class, PageForm::class, 'page', 'cover'],
]);
