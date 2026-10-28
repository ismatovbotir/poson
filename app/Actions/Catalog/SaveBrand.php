<?php

namespace App\Actions\Catalog;

use App\Models\Brand;
use App\Models\Product;
use App\Rules\BrandSafeText;
use App\Support\Catalog\HttpsUrl;
use App\Support\Catalog\ProductSearchText;
use App\Support\HtmlSanitizer;
use App\Support\Site\StructuredData;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a brand (fields only; the logo goes through UpdateCatalogImage).
 * The slug is generated once from the name and never changes after that
 * (incoming slugs are ignored); the description is sanitized.
 * Owns its invariants: a name is required and the website must be a valid
 * https link (HttpsUrl), and the description and SEO meta title /
 * description (shown on the public brand page and in search results) carry
 * no low-price / discount wording (BrandSafeText); the meta texts are
 * plain text of at most Brand::META_TITLE_MAX / META_DESCRIPTION_MAX.
 * Renaming refreshes the search_text of the brand's products.
 */
class SaveBrand
{
    /**
     * @param  array{name: string, description: array<string, string|null>, meta_title?: array<string, string|null>, meta_description?: array<string, string|null>, website: string|null, is_active: bool, sort: int|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(?Brand $brand, array $data): Brand
    {
        if (trim($data['name']) === '') {
            throw ValidationException::withMessages(['name' => __('validation.required', ['attribute' => __('admin.catalog.name')])]);
        }

        $website = filled($data['website']) ? trim((string) $data['website']) : null;

        // For software brands the website is also the "Try" partner / referral link (ProductCtas), kept as entered.
        if ($website !== null && ! HttpsUrl::isValid($website)) {
            throw ValidationException::withMessages(['website' => __('admin.brands.errors.website_https')]);
        }

        $descriptionErrors = [];

        foreach ($data['description'] as $locale => $html) {
            $text = StructuredData::plainText((string) $html);

            if ($text !== '' && BrandSafeText::violates($text)) {
                $descriptionErrors["description.{$locale}"] = __('validation.brand_safe', ['attribute' => __('admin.catalog.description').' ('.strtoupper((string) $locale).')']);
            }
        }

        $meta = [
            'meta_title' => [Brand::META_TITLE_MAX, (array) ($data['meta_title'] ?? [])],
            'meta_description' => [Brand::META_DESCRIPTION_MAX, (array) ($data['meta_description'] ?? [])],
        ];

        foreach ($meta as $field => [$max, $values]) {
            foreach ($values as $locale => $value) {
                $text = trim((string) $value);
                $attribute = __("admin.catalog.{$field}").' ('.strtoupper((string) $locale).')';

                if (mb_strlen($text) > $max) {
                    $descriptionErrors["{$field}.{$locale}"] = __('validation.max.string', ['attribute' => $attribute, 'max' => $max]);
                } elseif ($text !== '' && BrandSafeText::violates($text)) {
                    $descriptionErrors["{$field}.{$locale}"] = __('validation.brand_safe', ['attribute' => $attribute]);
                }
            }
        }

        if ($descriptionErrors !== []) {
            throw ValidationException::withMessages($descriptionErrors);
        }

        $brand ??= new Brand;
        $ignoreId = $brand->exists ? $brand->id : null;
        $name = trim($data['name']);
        $stored = (string) $brand->getOriginal('slug');

        // The slug is generated once from the name and never changes after that (incoming slugs are ignored).
        $generated = ! ($brand->exists && $stored !== '');
        $slug = $generated ? Slug::uniqueFor(Brand::class, Slug::make($name), $ignoreId) : $stored;

        return DB::transaction(function () use ($brand, $data, $name, $slug, $website, $generated, $ignoreId): Brand {
            $brand->storeTranslations('description', array_map(HtmlSanitizer::cleanOrNull(...), $data['description']));

            // Plain text (stripped of tags): shown in <title> and the meta description.
            foreach (['meta_title', 'meta_description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $brand->storeTranslations($field, array_map(fn (mixed $value): string => trim(strip_tags((string) $value)), (array) $data[$field]));
                }
            }
            $brand->fill([
                'name' => $name,
                'slug' => $slug,
                'website' => $website,
                'is_active' => $data['is_active'],
                'sort' => $data['sort'] ?? (int) Brand::query()->max('sort') + 1,
            ]);

            // A brand created concurrently with the same name: next free slug, once.
            Slug::saveGenerated($brand, $generated, fn (string $clashed): string => Slug::uniqueFor(Brand::class, Slug::make($name), $ignoreId, $clashed));

            if ($brand->wasChanged('name')) {
                $this->refreshProductSearchText($brand);
            }

            return $brand;
        });
    }

    /**
     * products.search_text contains the brand name: rebuild it for the brand's
     * products (trashed ones too), in chunks, inside the save transaction.
     */
    private function refreshProductSearchText(Brand $brand): void
    {
        Product::withTrashed()
            ->where('brand_id', $brand->id)
            ->select(['id', 'brand_id', 'sku', 'model', 'name'])
            ->chunkById(200, function ($products) use ($brand): void {
                foreach ($products as $product) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['search_text' => ProductSearchText::build($product, $brand->name)]);
                }
            });
    }
}
