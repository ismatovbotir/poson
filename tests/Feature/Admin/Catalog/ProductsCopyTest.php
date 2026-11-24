<?php

use App\Enums\ProductType;
use App\Enums\Role;
use App\Livewire\Admin\Products\Index;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Rules\BrandSafeText;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function copyDialog(Product $product, ?Role $role = Role::Admin): Testable
{
    return Livewire::actingAs(staffUser($role))->test(Index::class)->set('selected', [$product->id])->call('openCopy');
}

it('opens the dialog prefilled with the SKU and the names plus a suffix, without any price data', function () {
    $source = Product::factory()->create(['sku' => 'ZD-1', 'name' => ['uz' => 'Printer', 'ru' => 'Принтер', 'en' => '']]);

    $component = copyDialog($source)->assertHasNoErrors()->assertSet('copySourceId', $source->id)
        ->assertSet('copyForm.sku', 'ZD-1')
        ->assertSet('copyForm.name', ['uz' => 'Printer (nusxa)', 'ru' => 'Принтер (копия)', 'en' => '']);

    expect(array_keys($component->instance()->copyForm->all()))->toBe(['sku', 'name'])
        ->and(json_encode($component->instance()->copyForm->all()))->not->toContain((string) $source->price);
});

it('cuts a long name so the suffix fits and keeps empty locales empty', function () {
    $source = Product::factory()->create(['name' => ['uz' => 'Nom', 'ru' => str_repeat('я', 255), 'en' => '']]);

    $name = copyDialog($source)->get('copyForm.name');

    expect(mb_strlen($name['ru']))->toBe(255)->and($name['ru'])->toEndWith(' (копия)')->and($name['en'])->toBe('');
});

it('creates the copy through the dialog and opens its edit page with a toast', function () {
    $source = Product::factory()->create(['sku' => 'ZD-1']);

    $component = copyDialog($source)->set('copyForm.sku', 'zd-1-new')->call('copy')->assertHasNoErrors();

    $copy = Product::query()->where('sku', 'ZD-1-NEW')->firstOrFail();
    $component->assertRedirect(route('admin.products.edit', $copy))->assertSet('copySourceId', null)->assertSet('selected', []);
    expect(session('toast.body'))->toBe(__('admin.products.copy.created'))
        ->and(Product::query()->count())->toBe(2);
});

it('authorizes view, create and use of the type in both methods', function (Role $role, ProductType $type, bool $allowed) {
    $source = Product::factory()->create(['type' => $type]);
    $component = Livewire::actingAs(staffUser($role))->test(Index::class)->set('selected', [$source->id])->call('openCopy');

    if ($allowed) {
        $component->assertSet('copySourceId', $source->id);
    } else {
        $component->assertForbidden()->assertSet('copySourceId', null);
    }

    expect(Product::query()->count())->toBe(1);
})->with(function () {
    foreach ([Role::Admin, Role::ContentManager] as $role) {
        foreach (ProductType::cases() as $type) {
            yield "{$role->value} {$type->value}" => [$role, $type, true];
        }
    }

    foreach (ProductType::cases() as $type) {
        yield "service engineer {$type->value}" => [Role::ServiceEngineer, $type, $type->isServiceManaged()];
        yield "sales manager {$type->value}" => [Role::SalesManager, $type, false];
    }
});

it('re-authorizes copy() against the source type', function () {
    $source = Product::factory()->create(['type' => ProductType::Hardware]);
    $component = copyDialog($source);

    test()->actingAs(staffUser(Role::ServiceEngineer));
    $component->call('copy')->assertForbidden();

    expect(Product::query()->count())->toBe(1);
});

it('shows the Copy button only to roles that may copy, and not in the trash', function () {
    Product::factory()->create();

    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->assertSeeHtml('data-bulk-copy');
    Livewire::actingAs(staffUser(Role::ContentManager))->test(Index::class)->assertSeeHtml('data-bulk-copy');
    Livewire::actingAs(staffUser(Role::SalesManager))->test(Index::class)->assertDontSeeHtml('data-bulk-copy');
    Livewire::actingAs(staffUser(Role::Admin))->test(Index::class)->call('setTab', 'trash')->assertDontSeeHtml('data-bulk-copy');
});

it('hides the Copy button on products the role may not copy', function () {
    $hardware = Product::factory()->create(['type' => ProductType::Hardware]);

    Livewire::actingAs(staffUser(Role::ServiceEngineer))->test(Index::class)->assertDontSeeHtml('data-bulk-copy');
});

it('needs exactly one clean id in the selection', function (array $selected) {
    Product::factory()->create(['id' => 1]);
    Product::factory()->create(['id' => 2]);

    Livewire::actingAs(staffUser())->test(Index::class)->set('selected', $selected)->call('openCopy')
        ->assertHasErrors('selected')->assertSet('copySourceId', null);

    expect(Product::query()->count())->toBe(2);
})->with([
    'none' => [[]],
    'two' => [[1, 2]],
    'array' => [[['x']]],
    'suffixed digits' => [['1a']],
    'zero' => [[0]],
    'negative' => [['-1']],
]);

it('accepts one numeric string id', function () {
    Product::factory()->create(['id' => 5]);

    Livewire::actingAs(staffUser())->test(Index::class)->set('selected', ['5'])->call('openCopy')->assertSet('copySourceId', 5);
});

it('answers 404 for a trashed or unknown source and refuses the trash tab', function () {
    $trashed = Product::factory()->create();
    $trashed->delete();
    $admin = staffUser();

    Livewire::actingAs($admin)->test(Index::class)->set('selected', [$trashed->id])->call('openCopy')->assertNotFound();
    Livewire::actingAs($admin)->test(Index::class)->set('selected', [999_999])->call('openCopy')->assertNotFound();
    Livewire::actingAs($admin)->test(Index::class)->call('setTab', 'trash')->set('selected', [Product::factory()->create()->id])->call('openCopy')->assertHasErrors('selected');
});

it('locks the source id and refuses copy() without an open dialog', function () {
    $source = Product::factory()->create();
    $component = Livewire::actingAs(staffUser())->test(Index::class);

    expect(fn () => $component->set('copySourceId', $source->id))->toThrow(Exception::class, 'locked property');

    $component->set('copyForm.sku', 'NEW-1')->call('copy')->assertHasErrors('copyGeneral');
    expect(Product::query()->count())->toBe(1);
});

it('creates one copy only when the dialog is submitted twice or the SKU repeats', function () {
    $source = Product::factory()->create();
    $component = copyDialog($source)->set('copyForm.sku', 'DUP-1')->call('copy');
    $component->call('copy')->assertHasErrors('copyGeneral');

    expect(Product::query()->count())->toBe(2);

    copyDialog($source)->set('copyForm.sku', 'DUP-1')->call('copy')->assertHasErrors('copyForm.sku');
    expect(Product::query()->count())->toBe(2);
});

it('refuses the 11th copy within a minute', function () {
    $source = Product::factory()->create();
    $component = Livewire::actingAs(staffUser())->test(Index::class);

    foreach (range(1, 10) as $number) {
        $component->set('selected', [$source->id])->call('openCopy')->set('copyForm.sku', "RL-{$number}")->call('copy')->assertHasNoErrors();
    }

    $component->set('selected', [$source->id])->call('openCopy')->set('copyForm.sku', 'RL-11')->call('copy')->assertHasErrors('copyGeneral');
    expect(Product::query()->count())->toBe(11)->and(RateLimiter::tooManyAttempts('product-copy:'.auth()->id(), 10))->toBeTrue();
});

it('validates the dialog input shape and the brand rule', function (string $field, mixed $value, string $errorKey) {
    $source = Product::factory()->create();

    copyDialog($source)->set($field, $value)->call('copy')->assertHasErrors($errorKey);

    expect(Product::query()->count())->toBe(1);
})->with([
    'extra locale key' => ['copyForm.name.xx', 'x', 'copyForm.name'],
    'brand word in ru' => ['copyForm.name.ru', 'Скидка', 'copyForm.name.ru'],
    'empty sku' => ['copyForm.sku', '', 'copyForm.sku'],
    'long sku' => ['copyForm.sku', str_repeat('A', 65), 'copyForm.sku'],
]);

it('does not accept a sku sent as an array', function () {
    $source = Product::factory()->create();

    $failed = false;

    try {
        copyDialog($source)->set('copyForm.sku', ['x'])->call('copy');
    } catch (Throwable) {
        $failed = true;
    }

    expect($failed)->toBeTrue();
    expect(Product::query()->count())->toBe(1);
});

it('shows one general message for a legacy source text that breaks the brand rule, no raw key', function () {
    $source = Product::factory()->create();
    $source->setTranslation('description', 'ru', '<p>Большие скидки</p>')->saveQuietly();
    FaqItem::factory()->owner($source)->create();

    $component = copyDialog($source)->set('copyForm.sku', 'LEG-1')->call('copy');

    $component->assertHasErrors('copyGeneral')->assertHasNoErrors('copyForm.description');
    expect(Product::query()->count())->toBe(1)->and(FaqItem::query()->count())->toBe(1)
        ->and($component->errors()->first('copyGeneral'))->toBe(__('admin.products.copy.failed'));
});

it('selects only clean ids for bulk delete and activate', function () {
    $keep = Product::factory()->create(['id' => 1]);
    $drop = Product::factory()->create(['id' => 5]);
    $component = Livewire::actingAs(staffUser())->test(Index::class);

    $component->set('selected', ['1a', [1], null, 0, '-1'])->call('bulkDelete');
    expect(Product::query()->count())->toBe(2);

    $component->set('selected', ['5', 5, 'x'])->call('bulkDelete');
    expect($keep->fresh())->not->toBeNull()->and(Product::query()->whereKey($drop->id)->exists())->toBeFalse();
});

it('does not publish a product through an array or suffixed id', function () {
    $draft = Product::factory()->inactive()->create(['id' => 1]);

    Livewire::actingAs(staffUser())->test(Index::class)->set('selected', [['x'], '1a'])->call('bulkActivate');

    expect($draft->fresh()->is_active)->toBeFalse();
});

it('has the copy strings in uz, ru and en with the same keys and no brand-rule words', function () {
    $keys = array_keys(Arr::dot(trans('admin.products.copy', [], 'en')));

    foreach (['uz', 'ru', 'en'] as $locale) {
        $strings = Arr::dot(trans('admin.products.copy', [], $locale));

        expect(array_keys($strings))->toEqualCanonicalizing($keys);

        foreach ($strings as $key => $string) {
            expect(BrandSafeText::violates($string))->toBeFalse("{$locale}.{$key}");
        }
    }

    expect(trans('admin.products.copy.suffix', [], 'uz'))->toBe(' (nusxa)')
        ->and(trans('admin.products.copy.suffix', [], 'ru'))->toBe(' (копия)')
        ->and(trans('admin.products.copy.suffix', [], 'en'))->toBe(' (copy)')
        ->and(trans('admin.products.copy.created', [], 'uz'))->toContain('qoʻshing');
});

it('reports an invalid selection as a toast and a selected error', function () {
    Product::factory()->count(2)->create();

    Livewire::actingAs(staffUser())->test(Index::class)->set('selected', [])->call('openCopy')
        ->assertHasErrors('selected')
        ->assertDispatched('toast', title: __('admin.toast.error'), body: __('admin.products.copy.exactly_one'), type: 'error');
});

it('reports the trash refusal as a toast too', function () {
    $product = Product::factory()->create();

    Livewire::actingAs(staffUser())->test(Index::class)->call('setTab', 'trash')->set('selected', [$product->id])->call('openCopy')
        ->assertHasErrors('selected')
        ->assertDispatched('toast', body: __('admin.products.copy.exactly_one'), type: 'error');
});

it('shows the general error when the source cannot be copied', function () {
    $category = Category::factory()->create();
    $source = Product::factory()->inCategory($category)->create();

    foreach (range(1, Product::MAX_KEY_SPECS + 1) as $i) {
        $attribute = Attribute::factory()->create(['code' => "key_{$i}"]);
        $category->attributes()->attach($attribute->id, ['sort' => $i]);
        ProductAttributeValue::factory()->key()->create(['product_id' => $source->id, 'attribute_id' => $attribute->id, 'value_text' => ['uz' => "V{$i}"]]);
    }

    copyDialog($source)->set('copyForm.sku', 'NEW-KEYS')->call('copy')
        ->assertHasErrors('copyGeneral')
        ->assertSee(__('admin.products.copy.failed'));

    expect(Product::query()->count())->toBe(1);
});
