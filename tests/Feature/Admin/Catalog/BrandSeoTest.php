<?php

use App\Actions\Catalog\SaveBrand;
use App\Livewire\Admin\Brands\Form;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Brand SEO meta title / description (plan 12): saved per locale from the
 * brand form, rendered on the public brand page, default title and
 * description when empty.
 */
it('saves the meta title and description per locale', function () {
    $brand = Brand::factory()->create(['name' => 'Zebra']);

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['brand' => $brand])
        ->set('form.meta_title.ru', '  Zebra — подбор, настройка и сервис | Poson ')
        ->set('form.meta_description.ru', 'Сканеры и принтеры Zebra: подбор, интеграция, гарантия и <b>сервис</b>.')
        ->set('form.meta_title.en', 'Zebra — selection and service | Poson')
        ->call('save')
        ->assertHasNoErrors();

    $brand->refresh();

    expect($brand->getTranslation('meta_title', 'ru', false))->toBe('Zebra — подбор, настройка и сервис | Poson')
        ->and($brand->getTranslation('meta_description', 'ru', false))->toBe('Сканеры и принтеры Zebra: подбор, интеграция, гарантия и сервис.')
        ->and($brand->getTranslation('meta_title', 'en', false))->toBe('Zebra — selection and service | Poson')
        ->and($brand->getTranslations('meta_description'))->toBe(['ru' => 'Сканеры и принтеры Zebra: подбор, интеграция, гарантия и сервис.']);

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['brand' => $brand])
        ->assertSet('form.meta_title.ru', 'Zebra — подбор, настройка и сервис | Poson')
        ->assertSet('form.meta_title.uz', '');
});

it('rejects too long and brand-unsafe meta texts in the form and in the action', function (string $field, string $value) {
    $brand = Brand::factory()->create();

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['brand' => $brand])
        ->set("form.{$field}.uz", $value)
        ->call('save')
        ->assertHasErrors("form.{$field}.uz");

    expect(fn () => app(SaveBrand::class)->handle($brand, [
        'name' => $brand->name,
        'description' => [],
        $field => ['uz' => $value],
        'website' => null,
        'is_active' => true,
        'sort' => 1,
    ]))->toThrow(ValidationException::class)
        ->and($brand->fresh()->getTranslations($field))->toBe([]);
})->with([
    'title too long' => ['meta_title', str_repeat('a', Brand::META_TITLE_MAX + 1)],
    'description too long' => ['meta_description', str_repeat('a', Brand::META_DESCRIPTION_MAX + 1)],
    'banned title' => ['meta_title', 'Zebra eng arzon narxda'],
    'banned description' => ['meta_description', 'Zebra skanerlari chegirma bilan'],
]);

it('renders the meta title and description on the brand page, with the defaults as fallback', function () {
    $brand = Brand::factory()->create([
        'name' => 'Zebra',
        'slug' => 'zebra',
        'description' => ['uz' => '<p>Zebra skanerlari va printerlari.</p>'],
        'meta_title' => ['ru' => 'Zebra в Ташкенте: подбор и сервис | Poson'],
        'meta_description' => ['ru' => 'Оборудование Zebra с настройкой и гарантией.'],
    ]);
    Product::factory()->create(['brand_id' => $brand->id]);

    $ru = $this->get('/ru/brands/zebra')->assertOk()->getContent();
    $uz = $this->get('/uz/brands/zebra')->assertOk()->getContent();
    $en = $this->get('/en/brands/zebra')->assertOk()->getContent();

    expect($ru)->toContain('<title>'.e('Zebra в Ташкенте: подбор и сервис | Poson').'</title>')
        ->toContain('<meta name="description" content="'.e('Оборудование Zebra с настройкой и гарантией.').'">')
        // No uz meta: the default title and the description text.
        ->and($uz)->toContain('<title>'.e(__('site.brands.show.seo_title', ['name' => 'Zebra'], 'uz')).'</title>')
        ->toContain('<meta name="description" content="'.e('Zebra skanerlari va printerlari.').'">')
        // No en meta and no en description: the description falls back to uz.
        ->and($en)->toContain('<title>'.e(__('site.brands.show.seo_title', ['name' => 'Zebra'], 'en')).'</title>');
});
