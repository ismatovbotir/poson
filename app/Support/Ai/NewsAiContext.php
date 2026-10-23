<?php

namespace App\Support\Ai;

use App\Livewire\Forms\Admin\News\NewsPostForm;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;

/**
 * SI assist context of a news post (plan 07 7b), from the unsaved form:
 * titles, excerpts and the current text per locale (plain text, capped),
 * plus the names of the related products and categories, read from the
 * database by ID (never names sent by the browser). For a saved post the
 * subject and slug always come from the stored record. Company facts come
 * from the system prompt; no personal or CRM data (the author is never sent).
 */
final class NewsAiContext
{
    /**
     * Related products / categories named in the context.
     */
    public const MAX_RELATED = 10;

    public static function fromForm(NewsPostForm $form, ?NewsPost $post = null): AiContext
    {
        $stored = $post?->exists ? NewsPost::query()->find($post->id) : null;

        $facts = ProductAiContext::localized('Title', $form->title);
        $facts['News post'] = 'news/'.($stored?->slug ?? $form->slug);
        $facts += ProductAiContext::localized('Excerpt', $form->excerpt);

        $products = self::productNames($form->product_ids);

        if ($products !== '') {
            $facts['Related products'] = $products;
        }

        $categories = self::categoryNames($form->category_ids);

        if ($categories !== '') {
            $facts['Related categories'] = $categories;
        }

        $facts += ProductAiContext::localized('Current text', $form->body);

        return AiContext::fromArray([
            'name' => ProductAiContext::first($form->title),
            'category' => 'News of Poson',
            'facts' => $facts,
            'subject_type' => $stored !== null ? $stored->getMorphClass() : null,
            'subject_id' => $stored?->id,
        ]);
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private static function productNames(array $ids): string
    {
        $ids = array_slice(array_values(array_filter(array_map('intval', $ids))), 0, self::MAX_RELATED);

        if ($ids === []) {
            return '';
        }

        return Product::query()
            ->whereKey($ids)
            ->get(['id', 'sku', 'name'])
            ->map(fn (Product $product): string => trim(ProductAiContext::first($product->getTranslations('name'), 'en').' ('.$product->sku.')'))
            ->implode('; ');
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private static function categoryNames(array $ids): string
    {
        $ids = array_slice(array_values(array_filter(array_map('intval', $ids))), 0, self::MAX_RELATED);

        if ($ids === []) {
            return '';
        }

        return Category::query()
            ->whereKey($ids)
            ->pluck('id')
            ->map(fn ($id): string => CategoryTree::name((int) $id, 'en'))
            ->filter()
            ->implode('; ');
    }
}
