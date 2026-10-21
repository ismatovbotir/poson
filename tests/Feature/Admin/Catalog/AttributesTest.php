<?php

use App\Actions\Catalog\CreateAttributeOption;
use App\Enums\AttributeType;
use App\Enums\Role;
use App\Livewire\Admin\Attributes\Form;
use App\Livewire\Admin\Attributes\Index;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use Livewire\Livewire;

describe('index', function () {
    it('lists attributes with their usage without lazy loading', function () {
        $interface = Attribute::factory()->select()->create(['code' => 'interface', 'name' => ['uz' => 'Interfeys']]);
        Attribute::factory()->number('mm')->create(['code' => 'print_width']);
        Category::factory()->count(2)->create()->each(fn (Category $category) => $category->attributes()->attach($interface, ['sort' => 1]));

        $this->actingAs(staffUser())
            ->get(route('admin.attributes.index'))
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('interface')
            ->assertSee('print_width')
            ->assertSeeText(trans_choice('admin.attributes.in_categories', 2, ['count' => 2]));
    });

    it('searches by code or name and filters by type', function () {
        Attribute::factory()->select()->create(['code' => 'interface', 'name' => ['uz' => 'Interfeys']]);
        Attribute::factory()->number()->create(['code' => 'print_width', 'name' => ['uz' => 'Chop etish kengligi']]);

        Livewire::actingAs(staffUser())
            ->test(Index::class)
            ->set('search', 'kenglig')
            ->assertSee('print_width')
            ->assertDontSee('interface')
            ->set('search', '')
            ->set('type', 'select')
            ->assertSee('interface')
            ->assertDontSee('print_width');
    });

    it('deletes an attribute with its options and assignments', function () {
        $attribute = Attribute::factory()->select()->create();
        Category::factory()->create()->attributes()->attach($attribute, ['sort' => 1]);

        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Index::class)
            ->call('delete', $attribute->id)
            ->assertDispatched('toast');

        expect(Attribute::query()->count())->toBe(0)
            ->and(AttributeOption::query()->count())->toBe(0)
            ->and(DB::table('category_attribute')->count())->toBe(0);
    });
});

describe('form', function () {
    it('creates a select attribute with options', function () {
        Livewire::actingAs(staffUser(Role::ContentManager))
            ->test(Form::class)
            ->set('form.code', 'Print_Method')
            ->set('form.name.uz', 'Chop etish usuli')
            ->set('form.name.ru', 'Способ печати')
            ->set('form.type', 'select')
            ->set('form.options.0.value.uz', 'Termo')
            ->set('form.options.0.value.ru', 'Термо')
            ->call('addOption')
            ->set('form.options.1.value.uz', 'Termotransfer')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $attribute = Attribute::query()->sole();

        expect($attribute->code)->toBe('print_method')
            ->and($attribute->type)->toBe(AttributeType::Select)
            ->and($attribute->is_filterable)->toBeTrue()
            ->and($attribute->options->map(fn ($option) => $option->getTranslation('value', 'uz'))->all())->toBe(['Termo', 'Termotransfer'])
            ->and($attribute->options[0]->getTranslation('value', 'ru'))->toBe('Термо')
            ->and($attribute->options[1]->getRawOriginal('value'))->not->toContain('"ru"');
    });

    it('validates the code, required values and duplicates', function () {
        Attribute::factory()->create(['code' => 'taken']);

        Livewire::actingAs(staffUser())
            ->test(Form::class)
            ->set('form.code', '1bad code')
            ->set('form.type', 'select')
            ->set('form.options.0.value.uz', 'USB')
            ->call('addOption')
            ->set('form.options.1.value.uz', 'usb')
            ->call('save')
            ->assertHasErrors(['form.code' => 'regex', 'form.name.uz' => 'required', 'form.options.1.value.uz' => 'distinct'])
            ->set('form.code', 'taken')
            ->call('save')
            ->assertHasErrors(['form.code' => 'unique']);
    });

    it('updates, reorders, adds and removes options', function () {
        $attribute = Attribute::factory()->select(['USB', 'RS232', 'Bluetooth'])->create();
        $usb = $attribute->options[0];

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['attribute' => $attribute])
            ->assertSet('form.options.0.value.uz', 'USB')
            ->call('removeOption', 1)
            ->call('moveOption', 1, -1)
            ->call('addOption')
            ->set('form.options.2.value.uz', 'Wi-Fi')
            ->set('form.options.1.value.en', 'USB-A')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $options = $attribute->fresh()->options;

        expect($options->map(fn ($option) => $option->getTranslation('value', 'uz'))->all())->toBe(['Bluetooth', 'USB', 'Wi-Fi'])
            ->and($options[1]->id)->toBe($usb->id)
            ->and($options[1]->getTranslation('value', 'en'))->toBe('USB-A')
            ->and($options->pluck('sort')->all())->toBe([1, 2, 3]);
    });

    it('turns off filtering and drops options for text attributes', function () {
        $attribute = Attribute::factory()->select()->create();
        $category = Category::factory()->create();
        $category->attributes()->attach($attribute, ['sort' => 1, 'is_filterable' => true]);

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['attribute' => $attribute])
            ->set('form.type', 'text')
            ->assertSet('form.is_filterable', false)
            ->set('form.is_filterable', true)
            ->call('save')
            ->assertHasNoErrors();

        $attribute->refresh();

        expect($attribute->type)->toBe(AttributeType::Text)
            ->and($attribute->is_filterable)->toBeFalse()
            ->and($attribute->options()->count())->toBe(0)
            ->and($category->attributes()->first()->pivot->is_filterable)->toBeFalse();
    });
});

describe('options action', function () {
    it('deduplicates new options on the uz value', function () {
        $attribute = Attribute::factory()->select(['USB'])->create();

        $option = app(CreateAttributeOption::class)->handle($attribute, ['uz' => ' usb ', 'ru' => 'USB']);
        $new = app(CreateAttributeOption::class)->handle($attribute, ['uz' => 'Ethernet']);

        expect($option->id)->toBe($attribute->options[0]->id)
            ->and($attribute->options()->count())->toBe(2)
            ->and($new->sort)->toBe(2);
    });
});

describe('authorization', function () {
    it('lets every staff role view the screens', function (Role $role) {
        $attribute = Attribute::factory()->create();
        $user = staffUser($role);

        $this->actingAs($user)->get(route('admin.attributes.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.attributes.edit', $attribute))->assertOk();
    })->with([...catalogWriterRoles(), ...catalogViewOnlyRoles()]);

    it('forbids writes for view-only roles', function (Role $role) {
        $attribute = Attribute::factory()->create();
        $user = staffUser($role);

        $this->actingAs($user)->get(route('admin.attributes.create'))->assertForbidden();

        Livewire::actingAs($user)->test(Form::class, ['attribute' => $attribute])
            ->set('form.name.uz', 'Yangi')
            ->call('save')
            ->assertForbidden();

        Livewire::actingAs($user)->test(Index::class)->call('delete', $attribute->id)->assertForbidden();

        expect($attribute->fresh())->not->toBeNull();
    })->with(catalogViewOnlyRoles());
});
