<?php

use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SetProductActive;
use App\Actions\Quotes\SaveQuoteItems;
use App\Enums\ProductPricingUnit;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\Role;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Livewire\Admin\Products\Form;
use App\Models\Category;
use App\Models\Product;
use App\Models\RollType;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * SaveProduct input of an active label product priced per label (plan 08).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function labelProductData(array $overrides = []): array
{
    return array_replace([
        'type' => ProductType::Consumable,
        'category_id' => Category::factory()->create()->id,
        'brand_id' => null,
        'sku' => 'LBL-58X40',
        'model' => null,
        'slug' => null,
        'name' => ['uz' => 'Termoyorliqlar 58×40 mm', 'ru' => '', 'en' => ''],
        'short_description' => [],
        'description' => [],
        'meta_title' => [],
        'meta_description' => [],
        'price' => 1_200,
        'price_dealer' => 1_100,
        'price_wholesale' => null,
        'pricing_unit' => ProductPricingUnit::Label,
        'roll_type_ids' => [],
        'unit' => ProductUnit::Piece,
        'min_qty' => 5,
        'sale_mode' => SaleMode::Both,
        'stock_status' => StockStatus::InStock,
        'warranty_months' => null,
        'badge' => null,
        'is_active' => true,
        'is_featured' => false,
    ], $overrides);
}

/**
 * @return array<string, list<string>>
 */
function labelSaveErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('saves a label product with its roll types in order and forces the roll unit', function () {
    $first = RollType::factory()->size(1000, '40.0')->create();
    $second = RollType::factory()->size(500, '40.0')->create();

    $product = app(SaveProduct::class)->handle(null, labelProductData(['roll_type_ids' => [$second->id, $first->id]]));

    expect($product->pricing_unit)->toBe(ProductPricingUnit::Label)
        ->and($product->isPricedPerLabel())->toBeTrue()
        ->and($product->unit)->toBe(ProductUnit::Roll)
        ->and($product->price)->toBe(1_200)
        ->and($product->rollTypes()->pluck('roll_types.id')->all())->toBe([$second->id, $first->id])
        ->and($product->rollTypes()->pluck('product_roll_type.sort')->all())->toBe([1, 2]);

    // Re-ordering and detaching.
    app(SaveProduct::class)->handle($product, labelProductData(['sku' => 'LBL-58X40', 'roll_type_ids' => [$first->id]]));

    expect($product->fresh()->rollTypes()->pluck('roll_types.id')->all())->toBe([$first->id]);
});

it('allows label pricing for consumables only', function () {
    $roll = RollType::factory()->create();

    expect(labelSaveErrors(fn () => app(SaveProduct::class)->handle(null, labelProductData(['type' => ProductType::Hardware, 'roll_type_ids' => [$roll->id]]))))->toHaveKey('pricing_unit');
});

it('needs whole soʻm per label at every price level, a null price is fine', function () {
    $roll = RollType::factory()->create();

    $errors = labelSaveErrors(fn () => app(SaveProduct::class)->handle(null, labelProductData(['price' => 1_250, 'price_dealer' => 1_101, 'price_wholesale' => 1_000, 'roll_type_ids' => [$roll->id]])));

    expect($errors)->toHaveKeys(['price', 'price_dealer'])
        ->not->toHaveKey('price_wholesale');

    $onRequest = app(SaveProduct::class)->handle(null, labelProductData(['price' => null, 'price_dealer' => null, 'roll_type_ids' => [$roll->id]]));

    expect($onRequest->price)->toBeNull()
        ->and($onRequest->sale_mode)->toBe(SaleMode::Quote);
});

it('needs at least one active roll type for an active label product, not for a draft', function () {
    $inactive = RollType::factory()->inactive()->create();

    expect(labelSaveErrors(fn () => app(SaveProduct::class)->handle(null, labelProductData())))->toHaveKey('roll_type_ids')
        ->and(labelSaveErrors(fn () => app(SaveProduct::class)->handle(null, labelProductData(['roll_type_ids' => [$inactive->id]]))))->toHaveKey('roll_type_ids')
        ->and(labelSaveErrors(fn () => app(SaveProduct::class)->handle(null, labelProductData(['roll_type_ids' => [999_999]]))))->toHaveKey('roll_type_ids');

    $draft = app(SaveProduct::class)->handle(null, labelProductData(['is_active' => false, 'roll_type_ids' => [$inactive->id]]));

    expect($draft->is_active)->toBeFalse()
        ->and(app(SetProductActive::class)->handle($draft, true))->toBeFalse()
        ->and($draft->fresh()->is_active)->toBeFalse();
});

it('detaches roll types when the product goes back to unit pricing', function () {
    $product = Product::factory()->perLabel()->withRollTypes()->create();

    app(SaveProduct::class)->handle($product, labelProductData([
        'sku' => $product->sku,
        'category_id' => $product->category_id,
        'pricing_unit' => ProductPricingUnit::Unit,
        'unit' => ProductUnit::Box,
        'price' => 1_250,
    ]));

    expect($product->fresh())
        ->pricing_unit->toBe(ProductPricingUnit::Unit)
        ->unit->toBe(ProductUnit::Box)
        ->price->toBe(1_250)
        ->and($product->rollTypes()->count())->toBe(0);
});

it('keeps the roll types when the input has none, and still edits them on a price-synced product', function () {
    $roll = RollType::factory()->create();
    $added = RollType::factory()->create();
    $product = Product::factory()->perLabel()->withRollTypes([$roll])->priceSynced()->create();
    $data = labelProductData(['sku' => $product->sku, 'category_id' => $product->category_id, 'price' => 9_900]);
    unset($data['roll_type_ids']);

    app(SaveProduct::class)->handle($product, $data);

    expect($product->fresh()->price)->toBe(1_200)
        ->and($product->rollTypes()->pluck('roll_types.id')->all())->toBe([$roll->id]);

    app(SaveProduct::class)->handle($product->fresh(), [...$data, 'roll_type_ids' => [$added->id, $roll->id]]);

    expect($product->fresh()->price)->toBe(1_200)
        ->and($product->rollTypes()->pluck('roll_types.id')->all())->toBe([$added->id, $roll->id]);
});

it('picks, orders and saves roll types in the product form', function () {
    $category = Category::factory()->create();
    $a = RollType::factory()->size(500, '40.0')->create();
    $b = RollType::factory()->size(1000, '40.0')->create();

    $component = Livewire::actingAs(staffUser(Role::ContentManager))
        ->test(Form::class)
        ->set('form.name.uz', 'Termoyorliqlar')
        ->set('form.type', 'consumable')
        ->set('form.category_id', (string) $category->id)
        ->set('form.sku', 'lbl-1')
        ->set('form.pricing_unit', 'label')
        ->assertSet('form.unit', 'roll')
        ->assertSee('data-roll-types', false)
        ->call('toggleRollType', $a->id)
        ->call('toggleRollType', $b->id)
        ->call('moveRollType', $b->id, -1)
        ->assertSet('form.roll_type_ids', [$b->id, $a->id])
        ->set('form.price', '12')
        ->set('form.is_active', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $product = Product::query()->sole();

    expect($product->pricing_unit)->toBe(ProductPricingUnit::Label)
        ->and($product->price)->toBe(1_200)
        ->and($product->rollTypes()->pluck('roll_types.id')->all())->toBe([$b->id, $a->id]);

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['product' => $product])
        ->assertSet('form.pricing_unit', 'label')
        ->assertSet('form.roll_type_ids', [$b->id, $a->id])
        ->call('toggleRollType', $b->id)
        ->call('toggleRollType', $a->id)
        ->call('save')
        ->assertHasErrors(['form.roll_type_ids']);
});

it('locks the pricing unit of a price-synced product, also against a batch that arrived after the form loaded', function () {
    $roll = RollType::factory()->create();
    $synced = Product::factory()->perLabel()->withRollTypes([$roll])->priceSynced()->create();

    app(SaveProduct::class)->handle($synced, labelProductData([
        'sku' => $synced->sku,
        'category_id' => $synced->category_id,
        'roll_type_ids' => [$roll->id],
        'pricing_unit' => ProductPricingUnit::Unit,
        'unit' => ProductUnit::Box,
    ]));

    expect($synced->fresh())
        ->pricing_unit->toBe(ProductPricingUnit::Label)
        ->unit->toBe(ProductUnit::Roll)
        ->and($synced->rollTypes()->pluck('roll_types.id')->all())->toBe([$roll->id]);

    // Loaded unsynced, then 1C took the prices over before the save.
    $loaded = Product::factory()->perLabel()->withRollTypes([$roll])->create();
    $loaded = $loaded->fresh();
    Product::query()->whereKey($loaded->id)->update(['price_synced_at' => now()]);

    app(SaveProduct::class)->handle($loaded, labelProductData([
        'sku' => $loaded->sku,
        'category_id' => $loaded->category_id,
        // The form keeps the roll types while the unit radio shows "unit".
        'roll_type_ids' => [$roll->id],
        'pricing_unit' => ProductPricingUnit::Unit,
        'unit' => ProductUnit::Box,
        'price' => 99_900,
    ]));

    expect($loaded->fresh())
        ->pricing_unit->toBe(ProductPricingUnit::Label)
        ->unit->toBe(ProductUnit::Roll)
        ->price->toBe(1_200)
        ->and($loaded->rollTypes()->pluck('roll_types.id')->all())->toBe([$roll->id]);
});

it('disables the pricing unit choice with a lock hint for a price-synced product', function () {
    $product = Product::factory()->perLabel()->withRollTypes()->priceSynced()->create();

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['product' => $product])
        ->assertSee('data-pricing-unit-locked', false)
        ->assertSeeHtml('value="label" class="mt-0.5 size-4 accent-brand-600" disabled');
});

it('caps the minimum order of a label product at the quote line maximum', function () {
    $roll = RollType::factory()->create();

    expect(labelSaveErrors(fn () => app(SaveProduct::class)->handle(null, labelProductData(['roll_type_ids' => [$roll->id], 'min_qty' => SaveQuoteItems::MAX_QTY + 1]))))->toHaveKey('min_qty')
        ->and(app(SaveProduct::class)->handle(null, labelProductData(['roll_type_ids' => [$roll->id], 'min_qty' => SaveQuoteItems::MAX_QTY]))->min_qty)->toBe(SaveQuoteItems::MAX_QTY);
});
