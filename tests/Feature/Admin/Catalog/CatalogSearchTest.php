<?php

use App\Livewire\Admin\Attributes\Index as AttributesIndex;
use App\Livewire\Admin\Categories\Form as CategoryForm;
use App\Livewire\Admin\Categories\Index as CategoriesIndex;
use App\Models\Attribute;
use App\Models\Category;
use Livewire\Livewire;

it('searches translated values, not the json keys', function (string $term) {
    Category::factory()->named(['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners'])->create(['slug' => 'scanners']);

    Livewire::actingAs(staffUser())
        ->test(CategoriesIndex::class)
        ->set('search', $term)
        ->assertSee(__('admin.catalog.no_results'))
        ->assertDontSee('data-category-results', false);
})->with(['uz', 'ru', 'en', '"']);

it('finds categories and attributes by a Cyrillic name', function () {
    // SQLite lower() folds ASCII only, so the term matches lowercase Cyrillic text here.
    Category::factory()->named(['uz' => 'Yorliq printerlari', 'ru' => 'Принтеры этикеток'])->create();
    Attribute::factory()->create(['code' => 'print_width', 'name' => ['uz' => 'Kenglik', 'ru' => 'Ширина печати']]);
    Attribute::factory()->create(['code' => 'other', 'name' => ['uz' => 'Boshqa', 'ru' => 'Другое']]);

    $user = staffUser();

    Livewire::actingAs($user)->test(CategoriesIndex::class)
        ->set('search', 'этикет')
        ->assertSee('Yorliq printerlari');

    Livewire::actingAs($user)->test(AttributesIndex::class)
        ->set('search', 'печати')
        ->assertSee('print_width')
        ->assertDontSee('other');

    Livewire::actingAs($user)->test(CategoryForm::class)
        ->set('attributeSearch', 'печати')
        ->assertSee('pick-'.Attribute::query()->where('code', 'print_width')->value('id'), false)
        ->set('attributeSearch', 'uz')
        ->assertDontSee('pick-', false);
});
