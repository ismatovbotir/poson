<?php

use App\Enums\Role;
use App\Livewire\Admin\Brands\Form;
use App\Livewire\Admin\Brands\Index;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        ->and($brand->getTranslation('description', 'ru'))->toBe('<p>Сканеры <a rel="nofollow">ссылка</a></p>')
        ->and($brand->getFirstMedia('logo'))->not->toBeNull()
        ->and($brand->imageAlt('uz'))->toBe('Honeywell logotipi');
});

it('accepts only https websites and unique slugs', function () {
    Brand::factory()->create(['slug' => 'zebra']);

    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.name', 'Zebra 2')
        ->set('form.slug', 'zebra')
        ->set('form.website', 'http://zebra.com')
        ->call('save')
        ->assertHasErrors(['form.website', 'form.slug' => 'unique']);
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
