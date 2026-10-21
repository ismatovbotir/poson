<?php

use App\Actions\Catalog\ReorderCategory;
use App\Actions\Catalog\SaveAttribute;
use App\Actions\Catalog\SaveBrand;
use App\Actions\Catalog\SaveCategory;
use App\Actions\Catalog\SyncCategoryAttributes;
use App\Enums\AttributeType;
use App\Models\Attribute;
use App\Models\Category;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function integrityCategoryData(array $overrides = []): array
{
    return array_replace([
        'parent_id' => null,
        'name' => ['uz' => 'Toifa', 'ru' => '', 'en' => ''],
        'description' => ['uz' => '', 'ru' => '', 'en' => ''],
        'meta_title' => ['uz' => '', 'ru' => '', 'en' => ''],
        'meta_description' => ['uz' => '', 'ru' => '', 'en' => ''],
        'slug' => '',
        'icon' => null,
        'sort' => null,
        'is_active' => true,
    ], $overrides);
}

it('removes attributes from descendants once an ancestor assigns them', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->child($root)->create();
    $grandchild = Category::factory()->child($child)->create();
    [$interface, $range, $own] = Attribute::factory()->count(3)->create();

    $child->attributes()->attach([$interface->id => ['sort' => 1], $own->id => ['sort' => 2]]);
    $grandchild->attributes()->attach([$interface->id => ['sort' => 1], $range->id => ['sort' => 2]]);

    app(SyncCategoryAttributes::class)->handle($root, [
        ['attribute_id' => $interface->id, 'is_required' => true, 'is_filterable' => false],
    ]);

    expect($child->attributes()->pluck('attributes.id')->all())->toBe([$own->id])
        ->and($grandchild->attributes()->pluck('attributes.id')->all())->toBe([$range->id]);

    // The child's own attribute is pruned from the grandchild too.
    $grandchild->attributes()->attach($own->id, ['sort' => 3]);
    app(SyncCategoryAttributes::class)->handle($child, [['attribute_id' => $own->id, 'is_required' => false, 'is_filterable' => false]]);

    expect($grandchild->attributes()->pluck('attributes.id')->all())->toBe([$range->id]);
});

it('prunes inherited duplicates when a category moves to a new parent', function () {
    $newParent = Category::factory()->create();
    $moving = Category::factory()->create();
    $below = Category::factory()->child($moving)->create();
    $shared = Attribute::factory()->create();
    $kept = Attribute::factory()->create();

    $newParent->attributes()->attach($shared, ['sort' => 1]);
    $moving->attributes()->attach([$shared->id => ['sort' => 1], $kept->id => ['sort' => 2]]);
    $below->attributes()->attach($shared, ['sort' => 1]);

    app(SaveCategory::class)->handle($moving, integrityCategoryData([
        'parent_id' => $newParent->id,
        'name' => $moving->getTranslations('name'),
        'slug' => $moving->slug,
    ]));

    expect($moving->attributes()->pluck('attributes.id')->all())->toBe([$kept->id])
        ->and($below->attributes()->count())->toBe(0);
});

it('rejects a cyclic or too deep parent when called directly', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->child($root)->create();
    $grandchild = Category::factory()->child($child)->create();

    expect(fn () => app(SaveCategory::class)->handle($root, integrityCategoryData(['parent_id' => $child->id, 'slug' => $root->slug])))
        ->toThrow(ValidationException::class, __('admin.categories.errors.parent_cycle'))
        ->and(fn () => app(SaveCategory::class)->handle(null, integrityCategoryData(['parent_id' => $grandchild->id])))
        ->toThrow(ValidationException::class, __('admin.categories.errors.too_deep', ['max' => 3]));

    expect($root->fresh()->parent_id)->toBeNull()
        ->and(Category::query()->count())->toBe(3);
});

it('never changes parents or other branches when reordering', function () {
    $left = Category::factory()->create(['sort' => 1]);
    $right = Category::factory()->create(['sort' => 2]);
    $a = Category::factory()->child($left)->create(['sort' => 1]);
    $b = Category::factory()->child($left)->create(['sort' => 2]);
    $x = Category::factory()->child($right)->create(['sort' => 7]);
    $y = Category::factory()->child($right)->create(['sort' => 9]);

    app(ReorderCategory::class)->handle($b, 0);
    app(ReorderCategory::class)->handle($a, 99);

    expect(Category::query()->whereIn('id', [$a->id, $b->id])->pluck('parent_id')->unique()->all())->toBe([$left->id])
        ->and($x->fresh()->only(['parent_id', 'sort']))->toBe(['parent_id' => $right->id, 'sort' => 7])
        ->and($y->fresh()->only(['parent_id', 'sort']))->toBe(['parent_id' => $right->id, 'sort' => 9])
        ->and([$left->fresh()->sort, $right->fresh()->sort])->toBe([1, 2])
        ->and(Category::query()->where('parent_id', $left->id)->orderBy('sort')->pluck('id')->all())->toBe([$b->id, $a->id]);
});

it('requires a uz name in the save actions', function () {
    expect(fn () => app(SaveCategory::class)->handle(null, integrityCategoryData(['name' => ['uz' => ' ', 'ru' => 'Имя']])))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SaveAttribute::class)->handle(null, [
            'code' => 'x', 'name' => ['uz' => ''], 'unit' => null, 'type' => AttributeType::Text, 'is_filterable' => false, 'sort' => null,
        ]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveBrand::class)->handle(null, [
            'name' => '', 'slug' => '', 'description' => [], 'website' => null, 'is_active' => true, 'sort' => null,
        ]))->toThrow(ValidationException::class);
});

it('rejects a non-https brand website in the action', function (string $website) {
    expect(fn () => app(SaveBrand::class)->handle(null, [
        'name' => 'Zebra', 'slug' => '', 'description' => [], 'website' => $website, 'is_active' => true, 'sort' => null,
    ]))->toThrow(ValidationException::class, __('admin.brands.errors.website_https'));
})->with(['http://zebra.com', 'ftp://zebra.com', 'javascript:alert(1)', 'https://']);

it('keeps option ids stable when one is renamed and its old value is added again', function () {
    $attribute = Attribute::factory()->select(['USB', 'RS232'])->create();
    [$usb, $rs232] = $attribute->options;

    app(SaveAttribute::class)->handle($attribute, [
        'code' => $attribute->code, 'name' => $attribute->getTranslations('name'), 'unit' => null,
        'type' => AttributeType::Select, 'is_filterable' => true, 'sort' => null,
    ], [
        ['id' => null, 'value' => ['uz' => 'USB']],
        ['id' => $usb->id, 'value' => ['uz' => 'USB-C']],
        ['id' => null, 'value' => ['uz' => 'RS232']],
    ]);

    $options = $attribute->fresh()->options;

    expect($options->map(fn ($option) => $option->getTranslation('value', 'uz'))->all())->toBe(['USB', 'USB-C', 'RS232'])
        ->and($options[1]->id)->toBe($usb->id)
        ->and($options[2]->id)->toBe($rs232->id)
        ->and($options[0]->id)->not->toBe($usb->id);
});
