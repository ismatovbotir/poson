<?php

namespace App\Actions\Mcp;

use App\Actions\Catalog\SaveProduct;
use App\Enums\ProductPricingUnit;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Mcp\Support\SpecInput;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalog\ProductAttributeResolver;
use Database\Seeders\ProductsSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Create a product DRAFT for the MCP create_product_draft tool (plan 13 M3c,
 * M3-S1, S3, S4): SaveProduct with a null product (an existing product is
 * never loaded or changed).
 *
 * Forced values: inactive, no prices (price on request), pricing unit
 * "unit", stock on order, no badge, not featured; the fiscal-code and
 * roll-type keys are left out. An existing SKU (trashed included, any
 * case) or a SKU the seeder recorded is refused with one generic error that
 * says nothing about the existing product; a concurrent duplicate fails on
 * the unique index and gets the same error.
 */
class CreateMcpProductDraft
{
    public const SKU_EXISTS = 'SKU already exists.';

    public function __construct(private SaveProduct $save, private SpecInput $specs, private ProductAttributeResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $data  validated tool arguments
     * @return array{product: Product, missing_required: list<string>, issues: list<array{code: string}>, warnings: list<array{code: string}>}
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data): array
    {
        $type = ProductType::from((string) $data['type']);

        if (! Gate::forUser($user)->allows('create', [Product::class, $type])) {
            throw ValidationException::withMessages(['type' => 'You may not create products of this type.']);
        }

        $sku = mb_strtoupper(trim((string) $data['sku']));

        if ($this->skuTaken($sku)) {
            throw ValidationException::withMessages(['sku' => self::SKU_EXISTS]);
        }

        $specRows = $this->specs->resolve((int) $data['category_id'], (array) ($data['specs'] ?? []));

        try {
            $product = $this->save->handle(null, [
                'type' => $type,
                'category_id' => (int) $data['category_id'],
                'brand_id' => filled($data['brand_id'] ?? null) ? (int) $data['brand_id'] : null,
                'sku' => $sku,
                'model' => filled($data['model'] ?? null) ? (string) $data['model'] : null,
                'name' => $data['name'] ?? [],
                'short_description' => $data['short_description'] ?? [],
                'description' => $data['description'] ?? [],
                'meta_title' => $data['meta_title'] ?? [],
                'meta_description' => $data['meta_description'] ?? [],
                'price' => null,
                'price_dealer' => null,
                'price_wholesale' => null,
                'pricing_unit' => ProductPricingUnit::Unit,
                'unit' => ProductUnit::tryFrom((string) ($data['unit'] ?? '')) ?? ProductUnit::Piece,
                'min_qty' => (int) ($data['min_qty'] ?? 1),
                // Quote only: SaveProduct keeps it while there is no price, so an activated draft can't reach the cart priceless.
                'sale_mode' => SaleMode::Quote,
                'stock_status' => StockStatus::OnOrder,
                'warranty_months' => filled($data['warranty_months'] ?? null) ? (int) $data['warranty_months'] : null,
                'badge' => null,
                'is_active' => false,
                'is_featured' => false,
            ], $specRows);
        } catch (ValidationException $exception) {
            // A parallel call took the SKU after the check above: same generic answer.
            if (array_key_exists('sku', $exception->errors()) && $this->skuTaken($sku)) {
                throw ValidationException::withMessages(['sku' => self::SKU_EXISTS]);
            }

            throw $exception;
        }

        $missing = $this->missingRequired($product);

        return ['product' => $product, 'missing_required' => $missing, 'issues' => $this->issues($product), 'warnings' => $this->warnings($missing)];
    }

    /**
     * What staff still have to do before the draft can go live.
     *
     * @return list<array{code: string}>
     */
    private function issues(Product $product): array
    {
        $issues = [['code' => 'price_not_set'], ['code' => 'no_images']];

        foreach (['description', 'short_description'] as $field) {
            if (trim((string) $product->getTranslation($field, 'uz', false)) === '') {
                $issues[] = ['code' => 'no_'.$field.'_uz'];
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $missing
     * @return list<array{code: string}>
     */
    private function warnings(array $missing): array
    {
        return $missing === [] ? [['code' => 'created_inactive']] : [['code' => 'created_inactive'], ['code' => 'missing_required_specs']];
    }

    private function skuTaken(string $sku): bool
    {
        $recorded = array_map(mb_strtoupper(...), ProductsSeeder::recordedSkus());

        return in_array($sku, $recorded, true) || Product::withTrashed()->where('sku', $sku)->exists();
    }

    /**
     * Codes of required category specs without a value.
     *
     * @return list<string>
     */
    private function missingRequired(Product $product): array
    {
        $filled = $product->attributeValues()->pluck('attribute_id')->unique()->all();
        $missing = [];

        foreach ($this->resolver->forProduct($product) as $row) {
            if ($row->required && ! $row->skipped && ! in_array($row->attribute->id, $filled, true)) {
                $missing[] = $row->attribute->code;
            }
        }

        return $missing;
    }
}
