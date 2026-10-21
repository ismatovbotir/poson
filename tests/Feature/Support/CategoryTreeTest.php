<?php

use App\Models\Category;
use App\Support\Catalog\CategoryTree;

it('caches the tree and flushes it when a category changes', function () {
    $root = Category::factory()->create(['sort' => 2]);
    $child = Category::factory()->child($root)->create();

    expect(CategoryTree::nodes())->toHaveCount(2)
        ->and(CategoryTree::ancestorIds($child->id))->toBe([$root->id])
        ->and(CategoryTree::descendantIds($root->id))->toBe([$child->id])
        ->and(CategoryTree::depth($child->id))->toBe(2)
        ->and(CategoryTree::height($root->id))->toBe(2);

    $other = Category::factory()->create(['sort' => 1]);

    expect(array_column(CategoryTree::children(null), 'id'))->toBe([$other->id, $root->id])
        ->and(CategoryTree::flattened()[2])->toMatchArray(['id' => $child->id, 'depth' => 2]);

    $child->delete();

    expect(CategoryTree::find($child->id))->toBeNull();
});

it('explains why a parent is not allowed', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->child($root)->create();
    $grandchild = Category::factory()->child($child)->create();

    expect(CategoryTree::parentError($root->id, $root->id))->toBe(__('admin.categories.errors.parent_cycle'))
        ->and(CategoryTree::parentError($root->id, $grandchild->id))->toBe(__('admin.categories.errors.parent_cycle'))
        ->and(CategoryTree::parentError(null, $grandchild->id))->toBe(__('admin.categories.errors.too_deep', ['max' => 3]))
        ->and(CategoryTree::parentError(null, $child->id))->toBeNull()
        ->and(CategoryTree::parentError($grandchild->id, null))->toBeNull()
        ->and(CategoryTree::path($grandchild->id))->toContain(' › ');
});
