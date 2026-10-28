<?php

namespace App\Support\Ai;

use App\Livewire\Forms\Admin\Brands\BrandForm;
use App\Models\Brand;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;

/**
 * AI context of a brand (plan 12), from the unsaved brand form: the name,
 * the website, the description per locale (plain text, capped) and, for a
 * saved brand, up to MAX_CATEGORIES English names of the categories its
 * active products are in (one query; names from the cached CategoryTree).
 * Facts only: no prices, stock or CRM data.
 */
final class BrandAiContext
{
    public const MAX_CATEGORIES = 10;

    public static function fromForm(BrandForm $form, ?Brand $brand = null): AiContext
    {
        $facts = array_filter(['Website' => trim($form->website)]);
        $facts += ProductAiContext::localized('Current description', $form->description);

        $categories = $brand?->exists ? self::categoryNames($brand) : '';

        if ($categories !== '') {
            $facts['Equipment categories'] = $categories;
        }

        return AiContext::fromArray([
            'name' => trim($form->name),
            'facts' => $facts,
            'subject_type' => $brand?->exists ? $brand->getMorphClass() : null,
            'subject_id' => $brand?->exists ? $brand->id : null,
        ]);
    }

    /**
     * Distinct categories of the brand's active products, in English.
     */
    private static function categoryNames(Brand $brand): string
    {
        return Product::query()
            ->where('brand_id', $brand->id)
            ->where('is_active', true)
            ->distinct()
            ->limit(self::MAX_CATEGORIES)
            ->pluck('category_id')
            ->filter(fn (mixed $id): bool => $id !== null && CategoryTree::find((int) $id) !== null)
            ->map(fn (mixed $id): string => CategoryTree::name((int) $id, 'en'))
            ->filter()
            ->unique()
            ->implode('; ');
    }
}
