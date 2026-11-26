<?php

use App\Actions\Catalog\CreateAttributeOption;
use App\Actions\Catalog\SaveAttribute;
use App\Enums\AttributeType;
use App\Enums\Role;
use App\Livewire\Admin\Attributes\Form;
use App\Livewire\Admin\Products\Form as ProductForm;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Product;
use App\Support\HexColor;
use App\Support\Site\HomeCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Plan 38: the display colour of attribute options in admin (RD-1 – RD-6).
 */
function hexAttributeData(Attribute $attribute): array
{
    return [
        'code' => $attribute->code,
        'name' => $attribute->getTranslations('name'),
        'unit' => null,
        'type' => AttributeType::Select,
        'is_filterable' => true,
        'sort' => 10,
    ];
}

describe('RD-1 HexColor', function () {
    it('normalizes only "#" + 6 hex digits, upper case', function (mixed $value, ?string $expected) {
        expect(HexColor::normalize($value))->toBe($expected);
    })->with([
        ['#abcdef', '#ABCDEF'],
        ['#C8102E', '#C8102E'],
        ["#ABCDEF\n", null],
        ['#ABCDEFF', null],
        ['ABCDEF', null],
        ['#ABC', null],
        ['red', null],
        ['#12345g', null],
        [' #ABCDEF', null],
        [['#ABCDEF'], null],
        [0xABCDEF, null],
        [null, null],
    ]);

    it('computes the luminance and the light threshold', function () {
        expect(HexColor::luminance('#FFFFFF'))->toBe(1.0)
            ->and(HexColor::luminance('#000000'))->toBe(0.0)
            ->and(HexColor::isLight('#FFD100'))->toBeTrue()
            ->and(HexColor::isLight('#C9A227'))->toBeFalse()
            ->and(HexColor::isLight('nope'))->toBeFalse();
    });

    it('reads the stored column only through hexColor()', function () {
        $option = AttributeOption::factory()->withHex('#c8102e')->create();
        DB::table('attribute_options')->where('id', $option->id)->update(['hex_color' => '#00;x']);

        expect(AttributeOption::factory()->withHex('#c8102e')->create()->hexColor())->toBe('#C8102E')
            ->and($option->fresh()->hexColor())->toBeNull();
    });
});

describe('RD-2 validate on write', function () {
    it('rejects an invalid hex in SaveAttribute even without the form and stores nothing', function () {
        $attribute = Attribute::factory()->select(['Qora'])->create();
        $option = $attribute->options()->first();

        expect(fn () => app(SaveAttribute::class)->handle($attribute, [...hexAttributeData($attribute), 'sort' => 99], [
            ['id' => $option->id, 'value' => ['uz' => 'Qora yangi'], 'hex_color' => '#111111'],
            ['id' => null, 'value' => ['uz' => 'Oq'], 'hex_color' => '#000;x'],
        ]))->toThrow(ValidationException::class);

        expect($attribute->fresh()->sort)->toBe(0)
            ->and($option->fresh()->getTranslation('value', 'uz'))->toBe('Qora')
            ->and($option->fresh()->hexColor())->toBeNull()
            ->and($attribute->options()->count())->toBe(1);

        expect(fn () => app(CreateAttributeOption::class)->handle($attribute, ['uz' => 'Oq'], hex: "#ABCDEF\n"))->toThrow(ValidationException::class)
            ->and(fn () => app(SaveAttribute::class)->handle($attribute, hexAttributeData($attribute), [['id' => $option->id, 'value' => ['uz' => 'Qora'], 'hex_color' => ['#111111']]]))->toThrow(ValidationException::class)
            ->and($attribute->options()->count())->toBe(1)
            ->and($option->fresh()->hexColor())->toBeNull();
    });

    it('shows a hex error on the Livewire form for markup', function () {
        $attribute = Attribute::factory()->select(['Qora'])->create();

        Livewire::actingAs(staffUser())
            ->test(Form::class, ['attribute' => $attribute])
            ->set('form.options.0.hex_color', '<svg>')
            ->call('save')
            ->assertHasErrors(['form.options.0.hex_color']);

        expect($attribute->options()->first()->hexColor())->toBeNull();
    });

    it('stores a valid hex upper case and clears it when emptied', function () {
        $attribute = Attribute::factory()->select(['Qora'])->create();

        $component = Livewire::actingAs(staffUser())
            ->test(Form::class, ['attribute' => $attribute])
            ->set('form.options.0.hex_color', '#c8102e')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('form.options.0.hex_color', '#C8102E');

        expect($attribute->options()->first()->hex_color)->toBe('#C8102E');

        $component->set('form.options.0.hex_color', '')->call('save')->assertHasNoErrors();

        expect($attribute->options()->first()->hex_color)->toBeNull();
    });
});

describe('RD-3 admin swatch', function () {
    it('draws the swatch only from the normalized value of the live row', function (string $value) {
        $attribute = Attribute::factory()->select(['Qora'])->create();

        $html = Livewire::actingAs(staffUser())
            ->test(Form::class, ['attribute' => $attribute])
            ->set('form.options.0.hex_color', $value)
            ->html();

        preg_match('#data-section="options".*?</section>#s', $html, $section);

        expect($section[0])->not->toContain('data-hex-swatch')->not->toContain('<script')->not->toContain('background:url');
    })->with([
        'markup' => ['"><script>alert(1)</script>'],
        'css' => ['#000;background:url(x)'],
        'short' => ['#C81'],
    ]);

    it('draws the swatch for a valid unsaved value', function () {
        $attribute = Attribute::factory()->select(['Qora'])->create();

        $html = Livewire::actingAs(staffUser())
            ->test(Form::class, ['attribute' => $attribute])
            ->set('form.options.0.hex_color', '#c8102e')
            ->html();

        expect($html)->toContain('data-hex-swatch')->toContain('fill="#C8102E"');
    });
});

describe('RD-4 permissions', function () {
    it('forbids saving the attribute form for view-only roles', function (Role $role) {
        $attribute = Attribute::factory()->select(['Qora'])->create();

        Livewire::actingAs(staffUser($role))
            ->test(Form::class, ['attribute' => $attribute])
            ->set('form.options.0.hex_color', '#111111')
            ->call('save')
            ->assertForbidden();

        expect($attribute->options()->first()->hexColor())->toBeNull();
    })->with(catalogViewOnlyRoles());

    it('lets writer roles save a hex', function (Role $role) {
        $attribute = Attribute::factory()->select(['Qora'])->create();

        Livewire::actingAs(staffUser($role))
            ->test(Form::class, ['attribute' => $attribute])
            ->set('form.options.0.hex_color', '#111111')
            ->call('save')
            ->assertHasNoErrors();

        expect($attribute->options()->first()->hexColor())->toBe('#111111');
    })->with(catalogWriterRoles());

    it('keeps the inline product-form option creation without hex and forbidden without attributes.update', function () {
        $reflection = new ReflectionMethod(ProductForm::class, 'createOption');
        expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $reflection->getParameters()))->toBe(['attributeId', 'value', 'createOption']);

        $category = Category::factory()->create();
        $attribute = Attribute::factory()->select(['Qora'])->create();
        $category->attributes()->attach($attribute, ['sort' => 1]);
        $product = Product::factory()->inCategory($category)->create();

        Livewire::actingAs(staffUser(Role::SalesManager))
            ->test(ProductForm::class, ['product' => $product])
            ->call('createOption', $attribute->id, 'Oq')
            ->assertForbidden();

        expect($attribute->options()->count())->toBe(1);
    });
});

describe('RD-5 dedupe keeps the colour', function () {
    it('stores the hex of a removed and re-added option on the reused row', function () {
        $attribute = Attribute::factory()->select(['Qora', 'Oq'])->create();
        $black = $attribute->options()->where('sort', 1)->first();
        $white = $attribute->options()->where('sort', 2)->first();

        app(SaveAttribute::class)->handle($attribute, hexAttributeData($attribute), [
            ['id' => $white->id, 'value' => ['uz' => 'Oq'], 'hex_color' => null],
            ['id' => null, 'value' => ['uz' => 'qora'], 'hex_color' => '#111111'],
        ]);

        expect($attribute->options()->count())->toBe(2)
            ->and($black->fresh()->hex_color)->toBe('#111111')
            ->and($black->fresh()->getTranslation('value', 'uz'))->toBe('qora');
    });

    it('never wipes a stored hex when an inline create matches an existing option', function () {
        $attribute = Attribute::factory()->select(['Qora'])->create();
        $option = $attribute->options()->first();
        $option->forceFill(['hex_color' => '#111111'])->save();

        $same = app(CreateAttributeOption::class)->handle($attribute, ['uz' => ' QORA ']);

        expect($same->id)->toBe($option->id)->and($option->fresh()->hex_color)->toBe('#111111');

        $other = Attribute::factory()->select(['Oq'])->create();
        $filled = app(CreateAttributeOption::class)->handle($other, ['uz' => 'oq'], hex: '#ffffff');

        expect($filled->fresh()->hex_color)->toBe('#FFFFFF');
    });
});

describe('RD-6 home cache bump', function () {
    it('bumps after every committed save (plan 45 MA-51), not when it rolls back', function () {
        $attribute = Attribute::factory()->select(['Qora'])->create();
        $option = $attribute->options()->first();

        // MA-51: a save without a colour change bumps too (rename / filterable / unit relabel the Mini App facets).
        $before = HomeCache::version();
        app(SaveAttribute::class)->handle($attribute, hexAttributeData($attribute), [['id' => $option->id, 'value' => ['uz' => 'Qora'], 'hex_color' => null]]);
        expect(HomeCache::version())->not->toBe($before);

        $version = HomeCache::version();

        try {
            DB::transaction(function () use ($attribute, $option): void {
                app(SaveAttribute::class)->handle($attribute, hexAttributeData($attribute), [['id' => $option->id, 'value' => ['uz' => 'Qora'], 'hex_color' => '#111111']]);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        expect(HomeCache::version())->toBe($version)->and($option->fresh()->hex_color)->toBeNull();

        app(SaveAttribute::class)->handle($attribute, hexAttributeData($attribute), [['id' => $option->id, 'value' => ['uz' => 'Qora'], 'hex_color' => '#111111']]);

        expect(HomeCache::version())->not->toBe($version);
    });

    it('bumps when a product spec changes (SaveProduct always saves the product)', function () {
        $category = Category::factory()->create();
        $attribute = Attribute::factory()->select(['Qora', 'Oq'])->create();
        $category->attributes()->attach($attribute, ['sort' => 1]);
        $product = Product::factory()->inCategory($category)->create();
        $version = HomeCache::version();

        $component = Livewire::actingAs(staffUser())->test(ProductForm::class, ['product' => $product]);
        $index = collect($component->get('form.specs'))->search(fn (array $row): bool => (int) $row['attribute_id'] === $attribute->id);

        $component->set("form.specs.{$index}.option", (string) $attribute->options()->where('sort', 2)->value('id'))
            ->call('save')
            ->assertHasNoErrors();

        expect(HomeCache::version())->not->toBe($version);
    });
});
