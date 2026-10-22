<?php

namespace App\Support\Site;

use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Product;
use App\Rules\BrandSafeText;
use App\Support\Catalog\CategoryTree;
use Illuminate\Support\Collection;

/**
 * FAQ shown on public pages (plan 04 T12), one query per page:
 *
 *  - category listing: the category's own active items, else those of the
 *    nearest ancestor that has any;
 *  - product page: the product's items, else the category chain as above;
 *  - home page: the general FAQ (at most HOME_LIMIT).
 *
 * Items come out in the current locale (falling back to uz) as plain text;
 * empty or brand-unsafe items are left out (defense in depth: the admin
 * validates on save).
 *
 * forCategory() / forProduct() also say whether the items are the page's
 * own ("own" => true) or inherited from a category. Pages print FAQPage
 * JSON-LD only for their own items, so one FAQ is never marked up on many
 * URLs; inherited items are still shown in the HTML.
 *
 * @phpstan-type FaqItems list<array{question: string, answer: string}>
 * @phpstan-type FaqBlock array{items: FaqItems, own: bool}
 */
class FaqContent
{
    public const HOME_LIMIT = 6;

    /**
     * @return FaqBlock
     */
    public function forCategory(int $categoryId): array
    {
        $chain = [$categoryId, ...CategoryTree::ancestorIds($categoryId)];

        $items = FaqItem::query()
            ->active()
            ->where('faqable_type', (new Category)->getMorphClass())
            ->whereIn('faqable_id', $chain)
            ->ordered()
            ->get();

        return $this->firstNonEmpty($items, (new Category)->getMorphClass(), $chain, ownerId: $categoryId);
    }

    /**
     * @return FaqBlock
     */
    public function forProduct(Product $product): array
    {
        $productType = $product->getMorphClass();
        $categoryType = (new Category)->getMorphClass();
        $chain = [$product->category_id, ...CategoryTree::ancestorIds($product->category_id)];

        $items = FaqItem::query()
            ->active()
            ->where(fn ($query) => $query
                ->where(fn ($own) => $own->where('faqable_type', $productType)->where('faqable_id', $product->id))
                ->orWhere(fn ($category) => $category->where('faqable_type', $categoryType)->whereIn('faqable_id', $chain)))
            ->ordered()
            ->get();

        $own = $this->present($items->where('faqable_type', $productType));

        return $own !== [] ? ['items' => $own, 'own' => true] : $this->firstNonEmpty($items, $categoryType, $chain, ownerId: null);
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    public function general(int $limit = self::HOME_LIMIT): array
    {
        $items = FaqItem::query()
            ->active()
            ->ownedBy(null)
            ->ordered()
            ->limit($limit * 2)
            ->get();

        return array_slice($this->present($items), 0, $limit);
    }

    /**
     * Items of the first category in $chain (nearest first) that has any to
     * show; "own" when that category is the page's own ($ownerId).
     *
     * @param  Collection<int, FaqItem>  $items
     * @param  list<int>  $chain
     * @return FaqBlock
     */
    private function firstNonEmpty(Collection $items, string $type, array $chain, ?int $ownerId): array
    {
        foreach ($chain as $categoryId) {
            $present = $this->present($items->where('faqable_type', $type)->where('faqable_id', $categoryId));

            if ($present !== []) {
                return ['items' => $present, 'own' => $categoryId === $ownerId];
            }
        }

        return ['items' => [], 'own' => false];
    }

    /**
     * @param  Collection<int, FaqItem>  $items
     * @return list<array{question: string, answer: string}>
     */
    private function present(Collection $items): array
    {
        $present = [];

        foreach ($items as $item) {
            $question = trim((string) $item->question);
            $answer = trim((string) $item->answer);

            if ($question === '' || $answer === '' || BrandSafeText::violates($question.' '.$answer)) {
                continue;
            }

            $present[] = ['question' => $question, 'answer' => $answer];
        }

        return $present;
    }
}
