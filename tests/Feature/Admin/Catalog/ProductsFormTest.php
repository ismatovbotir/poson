<?php

use App\Enums\Role;
use App\Enums\SaleMode;
use App\Livewire\Admin\Products\Form;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

it('creates a product from whole soʻm and redirects to the edit page', function () {
    $category = Category::factory()->create();
    $brand = Brand::factory()->create(['name' => 'Zebra']);

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class)
        ->set('form.name.uz', 'Zebra DS2208 skaner')
        ->set('form.name.ru', 'Сканер Zebra DS2208')
        ->set('form.category_id', (string) $category->id)
        ->set('form.brand_id', (string) $brand->id)
        ->set('form.sku', 'zeb-ds2208')
        ->set('form.price', '1 450 000')
        ->set('form.price_dealer', '1300000')
        ->set('form.price_wholesale', '')
        ->set('form.description.uz', '<p>Matn <img src=x onerror=alert(1)></p>')
        ->set('form.is_active', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $product = Product::query()->sole();

    expect($product->sku)->toBe('ZEB-DS2208')
        ->and($product->slug)->toBe('zebra-ds2208-skaner')
        ->and($product->price)->toBe(145_000_000)
        ->and($product->price_dealer)->toBe(130_000_000)
        ->and($product->price_wholesale)->toBeNull()
        ->and($product->is_active)->toBeTrue()
        ->and($product->getTranslation('description', 'uz'))->toBe('<p>Matn </p>');
});

it('validates prices, SKU and the required fields', function () {
    Product::factory()->create(['sku' => 'TAKEN-1']);

    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.sku', 'taken-1')
        ->set('form.price', '12.5')
        ->set('form.min_qty', '0')
        ->call('save')
        ->assertHasErrors(['form.name.uz', 'form.category_id', 'form.sku' => 'unique', 'form.price', 'form.min_qty']);

    expect(Product::query()->count())->toBe(1);
});

it('shows the stored tiyin as whole soʻm and saves edits with a toast', function () {
    $product = Product::factory()->create(['price' => 125_000_000, 'price_dealer' => null]);

    $component = Livewire::actingAs(staffUser())
        ->test(Form::class, ['product' => $product])
        ->assertSet('form.price', '1 250 000')
        ->assertSet('form.price_dealer', '')
        ->set('form.price', '')
        ->set('form.sale_mode', 'cart')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    expect($product->fresh())->price->toBeNull()->sale_mode->toBe(SaleMode::Quote);
    $component->assertSee(__('admin.products.price_on_request'));
});

it('pre-fills specs from the category chain and keeps old values as extras on a category change', function () {
    $parent = Category::factory()->create();
    $category = Category::factory()->child($parent)->create(['name' => ['uz' => 'Qoʻl skanerlari']]);
    $other = Category::factory()->create();
    $inherited = Attribute::factory()->create(['code' => 'inherited_attr']);
    $own = Attribute::factory()->number()->create(['code' => 'own_attr', 'name' => ['uz' => 'Masofa']]);
    $empty = Attribute::factory()->create(['code' => 'empty_attr']);
    $parent->attributes()->attach($inherited, ['sort' => 1, 'is_required' => true]);
    $category->attributes()->attach([$own->id => ['sort' => 1], $empty->id => ['sort' => 2]]);

    $component = Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.category_id', (string) $category->id);

    expect(array_column($component->get('form.specs'), 'attribute_id'))->toBe([$inherited->id, $own->id, $empty->id]);
    $component->assertSee('Qoʻl skanerlari')->assertSee(trans_choice('admin.products.specs.missing', 1, ['count' => 1]));

    $component->set('form.specs.1.number', '12')
        ->set('form.category_id', (string) $other->id)
        ->assertSet('movedToExtras', ['Masofa'])
        ->assertSee('Masofa');

    expect(array_column($component->get('form.specs'), 'attribute_id'))->toBe([$own->id])
        ->and($component->get('form.specs.0.extra'))->toBeTrue();
});

it('saves a draft with specs, skips and key specs, and refuses to publish it incomplete', function () {
    $category = Category::factory()->create();
    $required = Attribute::factory()->select(['USB', 'Bluetooth'])->create();
    $optional = Attribute::factory()->boolean()->create();
    $category->attributes()->attach([$required->id => ['sort' => 1, 'is_required' => true], $optional->id => ['sort' => 2]]);

    $component = Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.name.uz', 'Skaner')
        ->set('form.sku', 'SK-1')
        ->set('form.category_id', (string) $category->id)
        ->call('toggleSkip', $required->id) // required: ignored
        ->call('toggleSkip', $optional->id)
        ->assertSet('form.specs.0.skipped', false)
        ->assertSet('form.specs.1.skipped', true)
        ->set('form.is_active', true)
        ->call('save')
        ->assertHasErrors(['form.specs', 'form.specs.0']);

    expect(Product::query()->count())->toBe(0);

    $component->set('form.is_active', false)->call('save')->assertHasNoErrors();
    $product = Product::query()->sole();

    expect($product->is_active)->toBeFalse()->and($product->has_missing_specs)->toBeTrue();

    $edit = Livewire::actingAs(staffUser())
        ->test(Form::class, ['product' => $product])
        ->assertSet('form.specs.1.skipped', true)
        ->call('selectOption', $required->id, $required->options->last()->id)
        ->call('toggleKey', $required->id)
        ->set('form.is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $value = ProductAttributeValue::query()->where('product_id', $product->id)->sole();

    expect($product->fresh())->is_active->toBeTrue()->has_missing_specs->toBeFalse()
        ->and($value->attribute_option_id)->toBe($required->options->last()->id)
        ->and($value->is_key)->toBeTrue();
});

it('limits key specs to four in the form', function () {
    $category = Category::factory()->create();
    $attributes = Attribute::factory()->count(5)->create();
    $category->attributes()->attach($attributes->mapWithKeys(fn ($attribute, $index) => [$attribute->id => ['sort' => $index]])->all());

    $component = Livewire::actingAs(staffUser())->test(Form::class)->set('form.category_id', (string) $category->id);

    foreach ($attributes->keys() as $index) {
        $component->set("form.specs.{$index}.text.uz", 'qiymat');
    }

    foreach ($attributes as $attribute) {
        $component->call('toggleKey', $attribute->id);
    }

    $component->assertDispatched('toast', type: 'error');
    expect(collect($component->get('form.specs'))->where('is_key', true))->toHaveCount(4);
});

it('does not count a key mark on an empty spec row', function () {
    $category = Category::factory()->create();
    $attributes = Attribute::factory()->count(5)->create();
    $category->attributes()->attach($attributes->mapWithKeys(fn ($attribute, $index) => [$attribute->id => ['sort' => $index]])->all());

    $component = Livewire::actingAs(staffUser())->test(Form::class)->set('form.category_id', (string) $category->id);

    // Row 0 stays empty: its mark doesn't use up one of the four.
    foreach ($attributes->keys()->skip(1) as $index) {
        $component->set("form.specs.{$index}.text.uz", 'qiymat');
    }

    foreach ($attributes as $attribute) {
        $component->call('toggleKey', $attribute->id);
    }

    $component->assertNotDispatched('toast');
    expect(collect($component->get('form.specs'))->where('is_key', true))->toHaveCount(5);
});

it('keeps tiyin below one soʻm when the price input is unchanged', function () {
    $product = Product::factory()->create(['price' => 125_000_050, 'price_dealer' => 99_999_999]);

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['product' => $product])
        ->assertSet('form.price', '1 250 000')
        ->set('form.price_dealer', '1 100 000')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh())->price->toBe(125_000_050)->price_dealer->toBe(110_000_000);
});

it('rejects a non-ASCII SKU', function () {
    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.name.uz', 'Skaner')
        ->set('form.category_id', (string) Category::factory()->create()->id)
        ->set('form.sku', 'СКАНЕР-1')
        ->call('save')
        ->assertHasErrors(['form.sku' => 'regex']);
});

it('locks the product property', function () {
    $product = Product::factory()->create();
    $other = Product::factory()->create();

    Livewire::actingAs(staffUser())->test(Form::class, ['product' => $product])->set('product', $other->id);
})->throws(CannotUpdateLockedPropertyException::class);

it('adds and removes extra attributes and toggles multiselect values', function () {
    $category = Category::factory()->create();
    $extra = Attribute::factory()->multiselect(['QR', 'PDF417'])->create(['code' => 'symbologies']);

    $component = Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->set('form.category_id', (string) $category->id)
        ->set('extraSearch', 'symbol')
        ->assertSee('symbologies')
        ->call('addExtra', $extra->id)
        ->call('toggleOption', $extra->id, $extra->options->first()->id)
        ->call('toggleOption', $extra->id, $extra->options->last()->id)
        ->call('toggleOption', $extra->id, $extra->options->first()->id);

    expect($component->get('form.specs.0.options'))->toBe([$extra->options->last()->id]);

    $component->call('removeExtra', $extra->id)->assertSet('form.specs', []);
});

it('creates a new option inline only with attributes.update', function () {
    $category = Category::factory()->create();
    $select = Attribute::factory()->select(['IP54'])->create();
    $category->attributes()->attach($select, ['sort' => 1]);

    Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class)
        ->set('form.category_id', (string) $category->id)
        ->call('createOption', $select->id, 'IP65')
        ->assertDispatched('toast');

    $option = AttributeOption::query()->where('attribute_id', $select->id)->get()->last();
    expect($option->getTranslation('value', 'uz'))->toBe('IP65');

    Livewire::actingAs(staffUser(Role::ServiceEngineer))
        ->test(Form::class)
        ->set('form.category_id', (string) $category->id)
        ->call('createOption', $select->id, 'IP67')
        ->assertForbidden();

    expect($select->options()->count())->toBe(2);
});

it('limits the types a service engineer can choose and save', function () {
    $category = Category::factory()->create();
    $user = staffUser(Role::ServiceEngineer);

    $component = Livewire::actingAs($user)->test(Form::class)->assertSet('form.type', 'software');

    expect(array_map(fn ($type) => $type->value, $component->instance()->typeOptions()))->toBe(['software', 'service']);

    $fill = fn () => Livewire::actingAs($user)->test(Form::class)
        ->set('form.name.uz', 'Uskuna')
        ->set('form.sku', 'HW-9')
        ->set('form.category_id', (string) $category->id);

    $fill()->set('form.type', 'hardware')->call('save')->assertForbidden();
    $fill()->set('form.type', 'service')->call('save')->assertHasNoErrors();

    expect(Product::query()->sole()->type->value)->toBe('service');
});

it('lets a service engineer edit service products but only view hardware', function () {
    $user = staffUser(Role::ServiceEngineer);
    $hardware = Product::factory()->create(['name' => ['uz' => 'Printer']]);
    $service = Product::factory()->service()->create();

    $this->actingAs($user)->get(route('admin.products.edit', $hardware))->assertOk()->assertSee('data-read-only', false);

    Livewire::actingAs($user)->test(Form::class, ['product' => $hardware])->set('form.name.uz', 'X')->call('save')->assertForbidden();
    Livewire::actingAs($user)->test(Form::class, ['product' => $service])->set('form.warranty_months', '6')->call('save')->assertHasNoErrors();

    // Changing the type of a service product to hardware is not allowed.
    Livewire::actingAs($user)->test(Form::class, ['product' => $service])->set('form.type', 'hardware')->call('save')->assertForbidden();

    expect($hardware->fresh()->getTranslation('name', 'uz'))->toBe('Printer')
        ->and($service->fresh())->warranty_months->toBe(6)->type->value->toBe('service');
});

it('forbids create and writes for the sales manager', function () {
    $product = Product::factory()->service()->create();
    $user = staffUser(Role::SalesManager);

    $this->actingAs($user)->get(route('admin.products.create'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.products.edit', $product))->assertOk();

    Livewire::actingAs($user)->test(Form::class, ['product' => $product])->call('save')->assertForbidden();
    Livewire::actingAs($user)->test(Form::class, ['product' => $product])->call('delete')->assertForbidden();
});

it('moves the product to the trash from the danger zone', function () {
    $product = Product::factory()->create();

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['product' => $product])
        ->call('delete')
        ->assertRedirect(route('admin.products.index'));

    expect($product->fresh()->trashed())->toBeTrue();
    $this->actingAs(staffUser())->get(route('admin.products.edit', $product))->assertNotFound();
});

it('shows the slug warning, SEO preview and child sections on the edit page', function () {
    $product = Product::factory()->create();

    $this->actingAs(staffUser())
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertSee('data-slug-warning', false)
        ->assertSee('data-seo-preview', false)
        ->assertSee('data-product-images', false)
        ->assertSee('data-product-documents', false)
        ->assertSee('data-product-relations', false);

    $this->actingAs(staffUser())
        ->get(route('admin.products.create'))
        ->assertOk()
        ->assertSee('data-save-first', false)
        ->assertDontSee('data-product-images', false);
});

it('opens the edit page with a constant number of queries', function () {
    $category = Category::factory()->create();
    $user = staffUser();

    $count = function (Product $product) use ($user): int {
        Livewire::actingAs($user)->test(Form::class, ['product' => $product]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test(Form::class, ['product' => $product]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = Product::factory()->inCategory($category)->create();
    $category->attributes()->attach(Attribute::factory()->select()->create(), ['sort' => 1]);
    $fewQueries = $count($few);

    $category->attributes()->attach(Attribute::factory()->count(6)->select()->create()->mapWithKeys(fn ($a, $i) => [$a->id => ['sort' => $i + 2]])->all());
    $many = Product::factory()->inCategory($category)->create();

    expect($count($many))->toBe($fewQueries);
});
