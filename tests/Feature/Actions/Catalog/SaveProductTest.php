<?php

use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SyncCategoryAttributes;
use App\Enums\PriceLevel;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeSkip;
use App\Models\ProductAttributeValue;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productData(Category $category, array $overrides = []): array
{
    return array_replace([
        'type' => ProductType::Hardware,
        'category_id' => $category->id,
        'brand_id' => null,
        'sku' => 'PS-200',
        'model' => 'PS200',
        'slug' => null,
        'name' => ['uz' => 'Poson PS-200 skaner', 'ru' => 'Сканер Poson PS-200', 'en' => ''],
        'short_description' => ['uz' => '', 'ru' => '', 'en' => ''],
        'description' => ['uz' => '', 'ru' => '', 'en' => ''],
        'meta_title' => ['uz' => '', 'ru' => '', 'en' => ''],
        'meta_description' => ['uz' => '', 'ru' => '', 'en' => ''],
        'price' => 125_000_000,
        'price_dealer' => 115_000_000,
        'price_wholesale' => null,
        'unit' => ProductUnit::Piece,
        'min_qty' => 1,
        'sale_mode' => SaleMode::Both,
        'stock_status' => StockStatus::InStock,
        'warranty_months' => 12,
        'badge' => null,
        'is_active' => false,
        'is_featured' => false,
    ], $overrides);
}

/**
 * @throws ValidationException
 */
function saveProduct(?Product $product, array $data, array $specs = []): Product
{
    return app(SaveProduct::class)->handle($product, $data, $specs);
}

/**
 * The first error key of a failed save.
 *
 * @return array<string, list<string>>
 */
function saveErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('stores prices in tiyin and falls back from Dealer / Wholesale to Retail', function () {
    $product = saveProduct(null, productData(Category::factory()->create()));

    expect($product->price)->toBe(125_000_000)
        ->and($product->price_dealer)->toBe(115_000_000)
        ->and($product->price_wholesale)->toBeNull()
        ->and($product->priceFor(PriceLevel::Wholesale))->toBe(125_000_000)
        ->and($product->priceFor(PriceLevel::Dealer))->toBe(115_000_000)
        ->and($product->sale_mode)->toBe(SaleMode::Both);
});

it('forces quote-only for a product without a Retail price', function () {
    $product = saveProduct(null, productData(Category::factory()->create(), ['price' => null, 'sale_mode' => SaleMode::Cart]));

    expect($product->price)->toBeNull()
        ->and($product->isPriceOnRequest())->toBeTrue()
        ->and($product->sale_mode)->toBe(SaleMode::Quote);
});

it('rejects negative prices', function () {
    expect(saveErrors(fn () => saveProduct(null, productData(Category::factory()->create(), ['price_dealer' => -1]))))
        ->toHaveKey('price_dealer');
});

it('upper-cases and trims the SKU and keeps it unique including the trash', function () {
    $category = Category::factory()->create();
    $product = saveProduct(null, productData($category, ['sku' => '  ps-200x ']));

    expect($product->sku)->toBe('PS-200X');

    $product->delete();

    expect(saveErrors(fn () => saveProduct(null, productData($category, ['sku' => 'Ps-200x', 'name' => ['uz' => 'Boshqa']]))))
        ->toHaveKey('sku');
});

it('generates a unique slug from the en name, else uz, including trashed products', function () {
    $category = Category::factory()->create();

    $first = saveProduct(null, productData($category, ['sku' => 'A1', 'name' => ['uz' => 'Oʻzbek skaneri', 'ru' => '', 'en' => '']]));
    $first->delete();
    $second = saveProduct(null, productData($category, ['sku' => 'A2', 'name' => ['uz' => 'Oʻzbek skaneri', 'ru' => '', 'en' => '']]));
    $english = saveProduct(null, productData($category, ['sku' => 'A3', 'name' => ['uz' => 'Skaner', 'ru' => '', 'en' => 'Handy scanner']]));

    expect($first->slug)->toBe('ozbek-skaneri')
        ->and($second->slug)->toBe('ozbek-skaneri-2')
        ->and($english->slug)->toBe('handy-scanner')
        ->and(saveErrors(fn () => saveProduct(null, productData($category, ['sku' => 'A4', 'slug' => 'ozbek-skaneri']))))->toHaveKey('slug');
});

it('sanitizes the description and builds search_text', function () {
    $brand = Brand::factory()->create(['name' => 'Zebra']);
    $product = saveProduct(null, productData(Category::factory()->create(), [
        'brand_id' => $brand->id,
        'name' => ['uz' => 'Oʻzbekcha Skaner', 'ru' => 'Сканер ШТРИХ', 'en' => 'Scanner'],
        'description' => ['uz' => '<p>Matn<script>alert(1)</script></p>', 'ru' => '', 'en' => ''],
    ]));

    expect($product->getTranslation('description', 'uz'))->toBe('<p>Matn</p>')
        ->and($product->search_text)->toBe('ps-200 ps200 ozbekcha skaner сканер штрих scanner zebra');
});

it('stores spec values per attribute type, one row per multiselect option', function () {
    $category = Category::factory()->create();
    $text = Attribute::factory()->create();
    $number = Attribute::factory()->number('m')->create();
    $boolean = Attribute::factory()->boolean()->create();
    $select = Attribute::factory()->select(['USB', 'RS232'])->create();
    $multi = Attribute::factory()->multiselect(['QR', 'DataMatrix', 'PDF417'])->create();
    $category->attributes()->attach([$text->id => ['sort' => 1], $number->id => ['sort' => 2], $boolean->id => ['sort' => 3], $select->id => ['sort' => 4], $multi->id => ['sort' => 5]]);
    [$qr, $dm, $pdf] = $multi->options->all();

    $product = saveProduct(null, productData($category), [
        ['attribute_id' => $text->id, 'text' => ['uz' => 'Qora', 'ru' => 'Чёрный']],
        ['attribute_id' => $number->id, 'number' => '1,5', 'is_key' => true],
        ['attribute_id' => $boolean->id, 'bool' => '0'],
        ['attribute_id' => $select->id, 'option' => $select->options->last()->id],
        ['attribute_id' => $multi->id, 'options' => [$pdf->id, $qr->id], 'is_key' => true],
    ]);

    $values = ProductAttributeValue::query()->where('product_id', $product->id)->orderBy('sort')->get();

    expect($values)->toHaveCount(6)
        ->and($values[0]->getTranslation('value_text', 'ru'))->toBe('Чёрный')
        ->and($values[1]->numberForDisplay())->toBe('1.5')
        ->and($values[1]->is_key)->toBeTrue()
        ->and($values[2]->value_bool)->toBeFalse()
        ->and($values[3]->attribute_option_id)->toBe($select->options->last()->id)
        ->and($values->where('attribute_id', $multi->id)->pluck('attribute_option_id')->all())->toBe([$qr->id, $pdf->id])
        ->and($values->where('attribute_id', $multi->id)->every->is_key)->toBeTrue();
});

it('rejects options of another attribute and invalid numbers', function () {
    $category = Category::factory()->create();
    $select = Attribute::factory()->select()->create();
    $other = Attribute::factory()->select(['X'])->create();
    $number = Attribute::factory()->number()->create();

    $errors = saveErrors(fn () => saveProduct(null, productData($category), [
        ['attribute_id' => $select->id, 'option' => $other->options->first()->id],
        ['attribute_id' => $number->id, 'number' => 'abc'],
    ]));

    expect($errors)->toHaveKeys(['specs.0', 'specs.1.number'])
        ->and(Product::query()->count())->toBe(0);
});

it('allows at most four key specs', function () {
    $category = Category::factory()->create();
    $attributes = Attribute::factory()->count(5)->create();
    $specs = $attributes->map(fn (Attribute $attribute) => ['attribute_id' => $attribute->id, 'text' => ['uz' => 'x'], 'is_key' => true])->all();

    expect(saveErrors(fn () => saveProduct(null, productData($category), $specs)))->toHaveKey('specs');

    $specs[4]['is_key'] = false;
    $product = saveProduct(null, productData($category), $specs);

    expect(ProductAttributeValue::query()->where('product_id', $product->id)->where('is_key', true)->count())->toBe(4);
});

it('stores skips for optional category attributes and refuses to skip required ones', function () {
    $category = Category::factory()->create();
    $optional = Attribute::factory()->create();
    $required = Attribute::factory()->create();
    $category->attributes()->attach([$optional->id => ['sort' => 1], $required->id => ['sort' => 2, 'is_required' => true]]);

    $product = saveProduct(null, productData($category), [
        ['attribute_id' => $optional->id, 'skipped' => true, 'text' => ['uz' => 'ignored'], 'is_key' => true],
    ]);

    expect(ProductAttributeSkip::query()->where('product_id', $product->id)->pluck('attribute_id')->all())->toBe([$optional->id])
        ->and(ProductAttributeValue::query()->where('product_id', $product->id)->count())->toBe(0)
        ->and(saveErrors(fn () => saveProduct($product, productData($category), [['attribute_id' => $required->id, 'skipped' => true]])))->toHaveKey('specs.0');
});

it('enforces required specs only when activating', function () {
    $category = Category::factory()->create();
    $required = Attribute::factory()->create(['name' => ['uz' => 'Interfeys']]);
    $category->attributes()->attach($required, ['sort' => 1, 'is_required' => true]);
    $specs = [['attribute_id' => $required->id, 'text' => ['uz' => '']]];

    // A draft may be incomplete.
    $draft = saveProduct(null, productData($category), $specs);
    expect($draft->is_active)->toBeFalse()->and($draft->has_missing_specs)->toBeTrue();

    // Activating it is blocked.
    $errors = saveErrors(fn () => saveProduct($draft, productData($category, ['is_active' => true]), $specs));
    expect($errors)->toHaveKeys(['specs', 'specs.0'])
        ->and($errors['specs'][0])->toContain('Interfeys')
        ->and($draft->fresh()->is_active)->toBeFalse();

    // With the value it publishes and is complete.
    $active = saveProduct($draft->fresh(), productData($category, ['is_active' => true]), [['attribute_id' => $required->id, 'text' => ['uz' => 'USB']]]);
    expect($active->is_active)->toBeTrue()->and($active->has_missing_specs)->toBeFalse();

    // An already active product stays active when it becomes incomplete (flagged).
    $still = saveProduct($active->fresh(), productData($category, ['is_active' => true]), $specs);
    expect($still->is_active)->toBeTrue()->and($still->has_missing_specs)->toBeTrue();
});

it('blocks creating an active product with missing required specs', function () {
    $category = Category::factory()->create();
    $category->attributes()->attach(Attribute::factory()->create(), ['sort' => 1, 'is_required' => true]);

    expect(saveErrors(fn () => saveProduct(null, productData($category, ['is_active' => true]))))->toHaveKey('specs')
        ->and(Product::query()->count())->toBe(0);
});

it('recomputes has_missing_specs when category attributes change', function () {
    $parent = Category::factory()->create();
    $category = Category::factory()->child($parent)->create();
    $attribute = Attribute::factory()->create();
    $product = saveProduct(null, productData($category, ['is_active' => true]));

    expect($product->has_missing_specs)->toBeFalse();

    // A required attribute on the parent: the active product stays active, flagged.
    app(SyncCategoryAttributes::class)->handle($parent, [['attribute_id' => $attribute->id, 'is_required' => true, 'is_filterable' => false]]);

    expect($product->fresh())->has_missing_specs->toBeTrue()->is_active->toBeTrue();

    app(SyncCategoryAttributes::class)->handle($parent, [['attribute_id' => $attribute->id, 'is_required' => false, 'is_filterable' => false]]);

    expect($product->fresh()->has_missing_specs)->toBeFalse();
});

it('requires a uz name, an existing category and brand', function () {
    $errors = saveErrors(fn () => saveProduct(null, array_replace(productData(Category::factory()->create()), [
        'name' => ['uz' => ' '],
        'category_id' => 999_999,
        'brand_id' => 999_999,
        'min_qty' => 0,
    ])));

    expect($errors)->toHaveKeys(['name.uz', 'category_id', 'brand_id', 'min_qty']);
});

it('ignores a key mark on an empty row', function () {
    $category = Category::factory()->create();
    $attributes = Attribute::factory()->count(5)->create();
    $specs = $attributes->map(fn (Attribute $attribute) => ['attribute_id' => $attribute->id, 'text' => ['uz' => 'x'], 'is_key' => true])->all();
    $specs[0]['text'] = ['uz' => ''];

    $product = saveProduct(null, productData($category), $specs);

    expect(ProductAttributeValue::query()->where('product_id', $product->id)->where('is_key', true)->count())->toBe(4)
        ->and(ProductAttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $attributes[0]->id)->exists())->toBeFalse();
});

it('accepts only ASCII SKUs', function () {
    expect(saveErrors(fn () => saveProduct(null, productData(Category::factory()->create(), ['sku' => 'ШК-1']))))->toHaveKey('sku');
});

it('caps a long Cyrillic name’s slug and keeps it unique', function () {
    $category = Category::factory()->create();
    $name = mb_substr(str_repeat('Сканер штрих-кода беспроводной ', 20), 0, 255);

    $first = saveProduct(null, productData($category, ['sku' => 'L1', 'name' => ['uz' => $name, 'ru' => '', 'en' => '']]));
    $second = saveProduct(null, productData($category, ['sku' => 'L2', 'name' => ['uz' => $name, 'ru' => '', 'en' => '']]));

    expect(mb_strlen($name))->toBe(255)
        ->and(strlen($first->slug))->toBeLessThanOrEqual(240)
        ->and($first->slug)->not->toEndWith('-')
        ->and($second->slug)->toBe($first->slug.'-2')
        ->and(strlen($second->slug))->toBeLessThanOrEqual(255);
});
