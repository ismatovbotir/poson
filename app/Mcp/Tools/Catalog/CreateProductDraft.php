<?php

namespace App\Mcp\Tools\Catalog;

use App\Actions\Mcp\CreateMcpProductDraft;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Mcp\Tools\PosonTool;
use App\Models\Product;
use App\Models\User;
use App\Support\Catalog\ProductDraftRules;
use App\Support\Site\CanonicalHost;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * create_product_draft (plan 13 M3c, M3-S1..S4, S11, S12): a NEW inactive
 * product without prices. Never edits a product; an existing SKU is refused.
 */
#[Name('create_product_draft')]
#[Description('Creates a NEW product as an inactive draft without prices (price on request, on order); you cannot edit existing items — suggest changes to the user instead. Required: type (hardware, consumable, software, service), category_id, sku (new, letters/digits/. _ / + - space, max 64), name.uz. Optional: brand_id, model, short_description, description (HTML), meta_title, meta_description per locale (uz, ru, en), unit (not roll), min_qty, warranty_months and specs (the sale mode is quote-only until staff set a price): a list of {code, value | option | options, skipped, is_key}; codes must belong to the category (get_product or list_categories with_attributes shows them), value is text {uz, ru, en}, a number or true/false, option(s) are existing option labels in any locale. Nothing is created for an existing SKU. Prices, stock, badge, images, documents and activation are set by staff in the admin panel. Never write discount or low-price wording. Returns id, sku, has_missing_specs, missing_required (attribute codes), issues, admin_url and warnings.')]
class CreateProductDraft extends PosonTool
{
    protected function permission(): ?string
    {
        return null;
    }

    /**
     * Anyone who may create some product (software and services: products.manage-services).
     */
    public function permits(User $user): bool
    {
        return $user->can('create', Product::class);
    }

    public function group(): string
    {
        return 'catalog';
    }

    protected function isWrite(): bool
    {
        return true;
    }

    protected function rules(): array
    {
        return ProductDraftRules::rules();
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $result = app(CreateMcpProductDraft::class)->handle($user, $request->all());
        /** @var Product $product */
        $product = $result['product'];

        $this->subject = $product;

        return Response::structured([
            'id' => $product->id,
            'sku' => $product->sku,
            'has_missing_specs' => (bool) $product->has_missing_specs,
            'missing_required' => $result['missing_required'],
            'issues' => $result['issues'],
            'admin_url' => CanonicalHost::using(fn (): string => route('admin.products.edit', $product)),
            'warnings' => $result['warnings'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $locales = fn (string $description) => $schema->object([
            'uz' => $schema->string(),
            'ru' => $schema->string(),
            'en' => $schema->string(),
        ])->description($description);

        return [
            'type' => $schema->string()->enum(array_column(ProductType::cases(), 'value'))->required(),
            'category_id' => $schema->integer()->description('Existing category id.')->required(),
            'brand_id' => $schema->integer()->description('Existing brand id.'),
            'sku' => $schema->string()->description('New unique SKU (upper-cased).')->required(),
            'model' => $schema->string(),
            'name' => $locales('Name per locale; uz is required.')->required(),
            'short_description' => $locales('Short text per locale (max 500).'),
            'description' => $locales('Description HTML per locale.'),
            'meta_title' => $locales('SEO title per locale (max 255).'),
            'meta_description' => $locales('SEO description per locale (max 500).'),
            'unit' => $schema->string()->enum(array_values(array_diff(array_column(ProductUnit::cases(), 'value'), ['roll'])))->description('Default piece.'),
            'min_qty' => $schema->integer()->description('Minimum quantity, default 1.'),
            'warranty_months' => $schema->integer()->description('0-600.'),
            'specs' => $schema->array()->items($schema->object([
                'code' => $schema->string()->description('Attribute code of the category.')->required(),
                'value' => $schema->anyOf([
                    $schema->object(['uz' => $schema->string(), 'ru' => $schema->string(), 'en' => $schema->string()]),
                    $schema->number(),
                    $schema->boolean(),
                ])->description('Text spec: {uz, ru, en}; number spec: a number; yes/no spec: true or false.'),
                'option' => $schema->string()->description('Select spec: an existing option label (any locale).'),
                'options' => $schema->array()->items($schema->string())->description('Multiselect spec: existing option labels.'),
                'skipped' => $schema->boolean()->description('Skip an optional category attribute.'),
                'is_key' => $schema->boolean()->description('Show as a key spec (max 4).'),
            ]))->description('Up to 60 specs.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $codes = fn () => $schema->array()->items($schema->object(['code' => $schema->string()->required()]));

        return [
            'id' => $schema->integer()->required(),
            'sku' => $schema->string()->required(),
            'has_missing_specs' => $schema->boolean()->required(),
            'missing_required' => $schema->array()->items($schema->string())->required(),
            'issues' => $codes()->required(),
            'admin_url' => $schema->string()->required(),
            'warnings' => $codes()->required(),
        ];
    }
}
