<?php

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeSkip;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ResolvedAttribute;

/**
 * Resolver tests (plan 03 T11). They need the database (categories and
 * attributes), so they live under Feature like CategoryTreeTest.
 *
 * @param  list<ResolvedAttribute>  $rows
 * @return list<string>
 */
function resolvedCodes(array $rows): array
{
    return array_map(fn (ResolvedAttribute $row): string => $row->attribute->code, $rows);
}

/**
 * root (a) › child (b, c) › leaf (d)
 *
 * @return array{root: Category, child: Category, leaf: Category, a: Attribute, b: Attribute, c: Attribute, d: Attribute}
 */
function resolverTree(): array
{
    $root = Category::factory()->create();
    $child = Category::factory()->child($root)->create();
    $leaf = Category::factory()->child($child)->create();
    $a = Attribute::factory()->create(['code' => 'a_root']);
    $b = Attribute::factory()->create(['code' => 'b_child']);
    $c = Attribute::factory()->create(['code' => 'c_child']);
    $d = Attribute::factory()->create(['code' => 'd_leaf']);

    $root->attributes()->attach($a, ['sort' => 1, 'is_required' => true]);
    $child->attributes()->attach([$c->id => ['sort' => 1], $b->id => ['sort' => 2, 'is_filterable' => true]]);
    $leaf->attributes()->attach($d, ['sort' => 1]);

    return compact('root', 'child', 'leaf', 'a', 'b', 'c', 'd');
}

it('inherits the ancestors’ attributes, nearest ancestor first, then the category’s own', function () {
    $tree = resolverTree();

    $rows = app(ProductAttributeResolver::class)->categoryAttributes($tree['leaf']->id);

    expect(resolvedCodes($rows))->toBe(['c_child', 'b_child', 'a_root', 'd_leaf'])
        ->and($rows[0]->categoryId)->toBe($tree['child']->id)
        ->and($rows[2]->categoryId)->toBe($tree['root']->id)
        ->and($rows[2]->required)->toBeTrue()
        ->and($rows[1]->filterable)->toBeTrue()
        ->and($rows[3]->categoryId)->toBe($tree['leaf']->id)
        ->and(collect($rows)->contains(fn (ResolvedAttribute $row) => $row->isExtra()))->toBeFalse();
});

it('lets the nearest category win when an attribute repeats in the chain', function () {
    $tree = resolverTree();
    $tree['child']->attributes()->attach($tree['a'], ['sort' => 3, 'is_required' => false]);

    $rows = app(ProductAttributeResolver::class)->categoryAttributes($tree['leaf']->id);
    $a = collect($rows)->first(fn (ResolvedAttribute $row) => $row->attribute->code === 'a_root');

    expect(resolvedCodes($rows))->toBe(['c_child', 'b_child', 'a_root', 'd_leaf'])
        ->and($a->categoryId)->toBe($tree['child']->id)
        ->and($a->required)->toBeFalse();
});

it('marks optional skipped attributes and ignores skips of required ones', function () {
    $tree = resolverTree();
    $resolver = app(ProductAttributeResolver::class);

    $rows = collect($resolver->resolve($tree['leaf']->id, [$tree['b']->id, $tree['a']->id]))
        ->keyBy(fn (ResolvedAttribute $row) => $row->attribute->code);

    expect($rows['b_child']->skipped)->toBeTrue()
        ->and($rows['a_root']->skipped)->toBeFalse()
        ->and($rows['c_child']->skipped)->toBeFalse();

    // Restore = no skip row any more.
    $restored = collect($resolver->resolve($tree['leaf']->id, []))->keyBy(fn (ResolvedAttribute $row) => $row->attribute->code);
    expect($restored['b_child']->skipped)->toBeFalse();
});

it('appends extras from the library after the category rows', function () {
    $tree = resolverTree();
    $weight = Attribute::factory()->number('g')->create(['code' => 'weight']);
    $color = Attribute::factory()->select(['Black', 'Grey'])->create(['code' => 'color']);

    $rows = app(ProductAttributeResolver::class)->resolve($tree['leaf']->id, [], [$color->id, $tree['b']->id, $weight->id]);

    expect(resolvedCodes($rows))->toBe(['c_child', 'b_child', 'a_root', 'd_leaf', 'color', 'weight'])
        ->and($rows[4]->isExtra())->toBeTrue()
        ->and($rows[4]->required)->toBeFalse()
        ->and($rows[4]->attribute->relationLoaded('options'))->toBeTrue()
        ->and($rows[4]->attribute->options)->toHaveCount(2)
        ->and($rows[1]->isExtra())->toBeFalse();
});

it('keeps values outside the new category as extras when the category changes', function () {
    $tree = resolverTree();
    $other = Category::factory()->create();
    $e = Attribute::factory()->create(['code' => 'e_other']);
    $other->attributes()->attach($e, ['sort' => 1]);

    $product = Product::factory()->inCategory($tree['leaf'])->create();
    ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $tree['d']->id, 'sort' => 1]);
    ProductAttributeValue::factory()->for($product)->create(['attribute_id' => $tree['b']->id, 'sort' => 2]);
    ProductAttributeSkip::factory()->for($product)->create(['attribute_id' => $tree['c']->id]);

    $resolver = app(ProductAttributeResolver::class);
    $current = $resolver->forProduct($product);
    $moved = $resolver->forProduct($product, $other->id);

    expect(collect($current)->firstWhere(fn ($row) => $row->attribute->code === 'c_child')->skipped)->toBeTrue()
        ->and(resolvedCodes($moved))->toBe(['e_other', 'd_leaf', 'b_child'])
        ->and($moved[1]->isExtra())->toBeTrue()
        ->and($moved[2]->isExtra())->toBeTrue();
});

it('returns nothing for a missing category and lists required ids', function () {
    $tree = resolverTree();
    $resolver = app(ProductAttributeResolver::class);

    expect($resolver->categoryAttributes(null))->toBe([])
        ->and($resolver->categoryAttributes(999_999))->toBe([])
        ->and($resolver->requiredIds($tree['leaf']->id))->toBe([$tree['a']->id]);
});
