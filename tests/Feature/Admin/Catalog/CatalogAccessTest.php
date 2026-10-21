<?php

use App\Enums\Role;
use App\Livewire\Admin\Attributes\Form as AttributeForm;
use App\Livewire\Admin\Brands\Form as BrandForm;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\Categories\Index as CategoryIndex;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return list<string>
 */
function catalogUrls(): array
{
    return [
        route('admin.categories.index'),
        route('admin.categories.create'),
        route('admin.categories.edit', Category::factory()->create()),
        route('admin.attributes.index'),
        route('admin.attributes.create'),
        route('admin.attributes.edit', Attribute::factory()->create()),
        route('admin.brands.index'),
        route('admin.brands.create'),
        route('admin.brands.edit', Brand::factory()->create()),
    ];
}

it('sends guests to the login page on every catalog route', function () {
    foreach (catalogUrls() as $url) {
        $this->get($url)->assertRedirect(route('admin.login'));
    }
});

it('keeps users without a staff role out of every catalog route', function () {
    $user = User::factory()->create();

    foreach (catalogUrls() as $url) {
        $this->actingAs($user)->get($url)->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }
});

it('forbids moving and deleting from the forms for view-only roles', function (Role $role) {
    $first = Category::factory()->create(['sort' => 1]);
    $second = Category::factory()->create(['sort' => 2]);
    $attribute = Attribute::factory()->create();
    $brand = Brand::factory()->create();
    $user = staffUser($role);

    Livewire::actingAs($user)->test(CategoryIndex::class)->call('move', $second->id, -1)->assertForbidden();
    Livewire::actingAs($user)->test(CategoryForm::class, ['category' => $first])->call('delete')->assertForbidden();
    Livewire::actingAs($user)->test(AttributeForm::class, ['attribute' => $attribute])->call('delete')->assertForbidden();
    Livewire::actingAs($user)->test(BrandForm::class, ['brand' => $brand])->call('delete')->assertForbidden();

    expect(Category::query()->orderBy('sort')->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($attribute->fresh())->not->toBeNull()
        ->and($brand->fresh())->not->toBeNull();
})->with(catalogViewOnlyRoles());
