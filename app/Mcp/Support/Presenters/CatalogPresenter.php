<?php

namespace App\Mcp\Support\Presenters;

use App\Mcp\Support\UntrustedText;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Explicit field allow-lists of the catalog tools (plan 13 S3, S25-S29).
 * Only the Retail price column is ever read, as integer tiyin under
 * retail_price_tiyin; dealer / wholesale prices, IKPU / package codes,
 * badges, image and document paths are never part of the output.
 */
final class CatalogPresenter
{
    /**
     * Needs brand and category loaded.
     *
     * @return array<string, mixed>
     */
    public static function product(Product $product): array
    {
        $brand = $product->brand;
        $category = $product->category;

        return [
            'id' => $product->id,
            'sku' => UntrustedText::clean($product->sku, 64),
            'model' => UntrustedText::clean($product->model, 100),
            'slug' => $product->slug,
            'type' => $product->type->value,
            'name' => Texts::translated($product, 'name'),
            'brand' => $brand === null ? null : ['id' => $brand->id, 'name' => UntrustedText::clean($brand->name, 100)],
            'category' => $category === null ? null : [
                'id' => $category->id,
                'slug' => $category->slug,
                'name' => Texts::translated($category, 'name'),
            ],
            'is_active' => $product->is_active,
            'stock_status' => $product->stock_status->value,
            'sale_mode' => $product->sale_mode->value,
            'retail_price_tiyin' => Money::tiyinOrNull($product->price),
            'has_missing_specs' => $product->has_missing_specs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function productSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'sku' => $schema->string()->nullable(),
            'model' => $schema->string()->nullable(),
            'slug' => $schema->string()->required(),
            'type' => $schema->string()->required(),
            'name' => Texts::schema($schema),
            'brand' => $schema->object([
                'id' => $schema->integer()->required(),
                'name' => $schema->string()->nullable(),
            ])->nullable(),
            'category' => $schema->object([
                'id' => $schema->integer()->required(),
                'slug' => $schema->string()->required(),
                'name' => Texts::schema($schema),
            ])->nullable(),
            'is_active' => $schema->boolean()->required(),
            'stock_status' => $schema->string()->required(),
            'sale_mode' => $schema->string()->required(),
            'retail_price_tiyin' => $schema->integer()->nullable()->description('Retail price in tiyin; null = price on request.'),
            'has_missing_specs' => $schema->boolean()->required(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => UntrustedText::clean($brand->name, 100),
            'slug' => $brand->slug,
            'is_active' => $brand->is_active,
            'product_count' => (int) $brand->products_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(Category $category, int $depth): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'depth' => $depth,
            'slug' => $category->slug,
            'is_active' => $category->is_active,
            'sort' => $category->sort,
            'name' => Texts::translated($category, 'name'),
            'product_count' => (int) $category->products_count,
        ];
    }
}
