<?php

namespace App\Actions\Catalog;

use App\Actions\Faq\SaveFaqItem;
use App\Models\Product;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ProductFormData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copy a product as a new draft (plan 39). Works only through SaveProduct and
 * SaveFaqItem, in one outer transaction: any error rolls the whole copy back,
 * and the source is never written.
 *
 * Copied: type, category, brand, model, texts (descriptions are sanitized and
 * brand-checked again by SaveProduct), specs with skips and is_key, roll
 * types, unit, min quantity, warranty, stock status, outbound relations
 * (never to trashed products) and the FAQ.
 *
 * Not copied: SKU and names (given by the caller), slug (generated), images,
 * documents, videos, prices and the 1C link, SEO meta, IKPU and package code,
 * badge, news links, inbound relations. The copy is inactive and not featured.
 */
class DuplicateProduct
{
    public function __construct(
        private SaveProduct $saveProduct,
        private SaveFaqItem $saveFaqItem,
        private ProductAttributeResolver $resolver,
    ) {}

    /**
     * @param  array{sku: string, name: array<string, string|null>}  $overrides
     *
     * @throws ValidationException
     */
    public function handle(Product $source, array $overrides): Product
    {
        if ($source->trashed()) {
            throw ValidationException::withMessages(['product' => __('admin.products.copy.trashed')]);
        }

        $state = ProductFormData::fromProduct($source, $this->resolver->forProduct($source));
        $state['sku'] = (string) ($overrides['sku'] ?? '');
        $state['name'] = $this->names((array) ($overrides['name'] ?? []));
        $state['meta_title'] = $this->emptyTexts();
        $state['meta_description'] = $this->emptyTexts();
        $state['ikpu_code'] = '';
        $state['package_code'] = '';
        $state['badge'] = '';
        $state['is_active'] = false;
        $state['is_featured'] = false;

        $data = ProductFormData::saveData($state, null);
        $data['price'] = null;
        $data['price_dealer'] = null;
        $data['price_wholesale'] = null;

        $specs = ProductFormData::specInput($state['specs']);
        $relations = $this->outboundRelations($source);
        $faqItems = $source->faqItems()->get();

        return DB::transaction(function () use ($data, $specs, $relations, $faqItems): Product {
            $copy = $this->saveProduct->handle(null, $data, $specs);

            if ($relations !== []) {
                DB::table('product_relations')->insert(array_map(
                    fn (array $relation): array => ['product_id' => $copy->id, ...$relation],
                    $relations,
                ));
            }

            foreach ($faqItems as $item) {
                $this->saveFaqItem->handle($copy, null, [
                    'question' => $item->getTranslations('question'),
                    'answer' => $item->getTranslations('answer'),
                    'is_active' => (bool) $item->is_active,
                ]);
            }

            return $copy;
        });
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return array<string, string>
     */
    private function names(array $names): array
    {
        $result = [];

        foreach (config('app.supported_locales') as $locale) {
            $value = $names[$locale] ?? '';
            $result[$locale] = is_string($value) ? trim($value) : '';
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function emptyTexts(): array
    {
        return array_fill_keys(config('app.supported_locales'), '');
    }

    /**
     * Outbound relations to products that are not in the trash.
     *
     * @return list<array{related_id: int, type: string, sort: int}>
     */
    private function outboundRelations(Product $source): array
    {
        return DB::table('product_relations')
            ->join('products', 'products.id', '=', 'product_relations.related_id')
            ->where('product_relations.product_id', $source->id)
            ->whereNull('products.deleted_at')
            ->orderBy('product_relations.type')
            ->orderBy('product_relations.sort')
            ->orderBy('product_relations.id')
            ->get(['product_relations.related_id', 'product_relations.type', 'product_relations.sort'])
            ->map(fn (object $row): array => [
                'related_id' => (int) $row->related_id,
                'type' => (string) $row->type,
                'sort' => (int) $row->sort,
            ])
            ->all();
    }
}
