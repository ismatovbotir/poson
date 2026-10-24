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
 * The slug comes from the name when left empty; the description is sanitized.
 * Owns its invariants: a name is required and the website must be a valid
 * https link (HttpsUrl), and the description (shown on the public brand
 * page) carries no low-price / discount wording (BrandSafeText).
 * Renaming refreshes the search_text of the brand's products.
 */
class SaveBrand
{
    /**
     * @param  array{name: string, slug: string|null, description: array<string, string|null>, website: string|null, is_active: bool, sort: int|null}  $data
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

        if ($descriptionErrors !== []) {
            throw ValidationException::withMessages($descriptionErrors);
        }

        $brand ??= new Brand;
        $ignoreId = $brand->exists ? $brand->id : null;
        $name = trim($data['name']);
        $slug = Slug::make($data['slug'] ?? '');

        if ($slug !== '' && Slug::taken(Brand::class, $slug, $ignoreId)) {
            throw ValidationException::withMessages(['slug' => __('validation.unique', ['attribute' => __('admin.catalog.slug')])]);
        }

        $slug = $slug !== '' ? $slug : Slug::uniqueFor(Brand::class, Slug::make($name), $ignoreId);

        return DB::transaction(function () use ($brand, $data, $name, $slug, $website): Brand {
            $brand->storeTranslations('description', array_map(HtmlSanitizer::cleanOrNull(...), $data['description']));
            $brand->fill([
                'name' => $name,
                'slug' => $slug,
                'website' => $website,
                'is_active' => $data['is_active'],
                'sort' => $data['sort'] ?? (int) Brand::query()->max('sort') + 1,
            ])->save();

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
