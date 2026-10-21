<?php

use App\Enums\Role;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;

beforeEach(fn () => view()->share('errors', new ViewErrorBag));

it('links the sidebar catalog items to the real screens', function () {
    $this->actingAs(staffUser(Role::SalesManager))
        ->get('/admin')
        ->assertOk()
        ->assertSee('href="'.route('admin.categories.index').'"', false)
        ->assertSee('href="'.route('admin.attributes.index').'"', false)
        ->assertSee('href="'.route('admin.brands.index').'"', false);
});

it('shows real catalog counts on the dashboard', function () {
    Category::factory()->count(3)->create();
    Attribute::factory()->count(2)->create();
    Brand::factory()->create();

    $this->actingAs(staffUser(Role::ContentManager))
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder([__('admin.dashboard.cards.categories'), '3', __('admin.dashboard.cards.attributes'), '2', __('admin.dashboard.cards.brands'), '1']);
});

it('renders the rich text editor bound to its property', function () {
    $html = Blade::render('<x-admin.rich-text wire:model="form.description.uz" label="Tavsif" />');

    expect($html)
        ->toContain('wire:ignore')
        ->toContain("richText(\$wire.entangle('form.description.uz'))")
        ->toContain('role="toolbar"')
        ->toContain('id="f-form-description-uz-label"');
});

it('renders the image upload with alt fields for every locale', function () {
    $html = Blade::render('<x-admin.image-upload model="form.image" alt-model="form.image_alt" remove-action="removeImage" current-url="https://example.test/a.webp" />');

    expect($html)
        ->toContain('wire:model="form.image"')
        ->toContain('accept="image/jpeg,image/png,image/webp"')
        ->toContain('wire:model="form.image_alt.uz"')
        ->toContain('wire:model="form.image_alt.ru"')
        ->toContain('wire:model="form.image_alt.en"')
        ->toContain('wire:click="removeImage"');
});
