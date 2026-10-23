<?php

namespace App\Support\Ai;

use App\Livewire\Forms\Admin\Categories\CategoryForm;
use App\Models\Attribute;
use App\Models\Category;
use App\Support\Catalog\CategoryTree;

/**
 * AI context of a category, from the unsaved category form (fromForm) or a
 * saved category (fromCategory, e.g. for its FAQ): names in each locale,
 * the parent path, subcategories and the spec attributes its products get
 * (own and inherited), which the texts may mention as "what to compare".
 */
final class CategoryAiContext
{
    public static function fromForm(CategoryForm $form, ?Category $category = null): AiContext
    {
        $parentId = filled($form->parent_id) && CategoryTree::find((int) $form->parent_id) !== null ? (int) $form->parent_id : null;
        $attributeIds = array_map(fn (array $row): int => (int) $row['attribute_id'], $form->specs);

        if ($parentId !== null) {
            $chain = [$parentId, ...CategoryTree::ancestorIds($parentId)];
            $inherited = Category::query()->whereIn('id', $chain)->with('attributes:id')->get()
                ->flatMap(fn (Category $ancestor): array => $ancestor->getRelationValue('attributes')->modelKeys())
                ->all();
            $attributeIds = [...$inherited, ...$attributeIds];
        }

        return self::build(
            names: $form->name,
            parentId: $parentId,
            attributeIds: array_values(array_unique($attributeIds)),
            category: $category,
        );
    }

    public static function fromCategory(Category $category): AiContext
    {
        $ids = [$category->id, ...CategoryTree::ancestorIds($category->id)];
        $attributeIds = Category::query()->whereIn('id', $ids)->with('attributes:id')->get()
            ->flatMap(fn (Category $node): array => $node->getRelationValue('attributes')->modelKeys())
            ->unique()
            ->values()
            ->all();

        return self::build($category->getTranslations('name'), $category->parent_id, $attributeIds, $category);
    }

    /**
     * @param  array<string, mixed>  $names
     * @param  list<int>  $attributeIds
     */
    private static function build(array $names, ?int $parentId, array $attributeIds, ?Category $category): AiContext
    {
        $facts = ProductAiContext::localized('Name', $names);

        if ($category?->exists) {
            $children = array_map(fn (array $node): string => CategoryTree::name($node['id'], 'en'), CategoryTree::children($category->id));

            if ($children !== []) {
                $facts['Subcategories'] = implode(', ', $children);
            }
        }

        $specs = Attribute::query()->whereIn('id', $attributeIds)->orderBy('sort')->limit(40)->get()
            ->map(fn (Attribute $attribute): string => '- '.$attribute->getTranslation('name', 'en').($attribute->unit ? " ({$attribute->unit})" : ''))
            ->implode("\n");

        return AiContext::fromArray([
            'name' => ProductAiContext::first($names),
            'category' => $parentId !== null ? CategoryTree::path($parentId, 'en') : '',
            'specs' => $specs !== '' ? "Typical specs of products in this category:\n".$specs : '',
            'facts' => $facts,
            'subject_type' => $category?->exists ? $category->getMorphClass() : null,
            'subject_id' => $category?->exists ? $category->id : null,
        ]);
    }
}
