<?php

namespace Database\Factories;

use App\Enums\ProductBadge;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Catalog\ProductSearchText;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Active hardware product with a Retail price (Dealer / Wholesale a little
 * lower), sold through the cart and quotes. search_text is filled after
 * creating, as SaveProduct would.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));
        $number = fake()->unique()->numberBetween(1, 999_999);
        $sums = fake()->numberBetween(50, 20_000) * 1_000;

        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'type' => ProductType::Hardware,
            'sku' => 'SKU-'.$number,
            'model' => 'M'.$number,
            'slug' => Slug::make($name).'-'.$number,
            'name' => ['uz' => $name, 'ru' => 'RU '.$name, 'en' => 'EN '.$name],
            'short_description' => null,
            'description' => null,
            'price' => $sums * 100,
            'price_dealer' => (int) round($sums * 0.95) * 100,
            'price_wholesale' => (int) round($sums * 0.9) * 100,
            'unit' => ProductUnit::Piece,
            'min_qty' => 1,
            'sale_mode' => SaleMode::Both,
            'stock_status' => StockStatus::InStock,
            'warranty_months' => 12,
            'badge' => null,
            'is_active' => true,
            'is_featured' => false,
            'has_missing_specs' => false,
            'search_text' => null,
            'meta_title' => null,
            'meta_description' => null,
            'sort' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Product $product): void {
            $product->forceFill(['search_text' => ProductSearchText::build($product)])->saveQuietly();
        });
    }

    /**
     * A draft (not on the site).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function consumable(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::Consumable,
            'unit' => ProductUnit::Roll,
            'min_qty' => 10,
            'sale_mode' => SaleMode::Cart,
            'warranty_months' => null,
        ]);
    }

    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::Service,
            'unit' => ProductUnit::Hour,
            'sale_mode' => SaleMode::Quote,
            'warranty_months' => null,
        ]);
    }

    public function software(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::Software,
            'unit' => ProductUnit::License,
            'warranty_months' => null,
        ]);
    }

    /**
     * Has a price but is sold through quote requests only.
     */
    public function quoteOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'sale_mode' => SaleMode::Quote,
        ]);
    }

    /**
     * No Retail price: "price on request", always a quote.
     */
    public function priceOnRequest(): static
    {
        return $this->state(fn (array $attributes) => [
            'price' => null,
            'price_dealer' => null,
            'price_wholesale' => null,
            'sale_mode' => SaleMode::Quote,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
            'badge' => ProductBadge::Hit,
        ]);
    }

    /**
     * Product of the given category.
     */
    public function inCategory(Category $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }

    public function withoutBrand(): static
    {
        return $this->state(fn (array $attributes) => [
            'brand_id' => null,
        ]);
    }
}
