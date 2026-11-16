<?php

use App\Actions\Catalog\SaveProduct;
use App\Enums\AttributeType;
use App\Enums\ProductType;
use App\Enums\ProductUnit;
use App\Enums\Role;
use App\Enums\SaleMode;
use App\Enums\StockStatus;
use App\Livewire\Admin\Products\Form;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Rules\BrandSafeText;
use App\Support\Catalog\ProductTextRules;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Brand rule (no low-price / discount wording) on every product text and every caller.
 */
function saveProductData(array $over = []): array
{
    return [
        'type' => ProductType::Hardware,
        'category_id' => Category::factory()->create()->id,
        'brand_id' => null,
        'sku' => 'BS-1',
        'model' => null,
        'name' => ['uz' => 'Skaner'],
        'short_description' => [],
        'description' => [],
        'meta_title' => [],
        'meta_description' => [],
        'price' => null,
        'price_dealer' => null,
        'price_wholesale' => null,
        'unit' => ProductUnit::Piece,
        'min_qty' => 1,
        'sale_mode' => SaleMode::Quote,
        'stock_status' => StockStatus::InStock,
        'warranty_months' => null,
        'badge' => null,
        'is_active' => false,
        'is_featured' => false,
        ...$over,
    ];
}

function fillProductForm($component, array $over = [])
{
    $category = Category::factory()->create();

    $component->set('form.name.uz', 'Skaner')->set('form.category_id', (string) $category->id)->set('form.sku', 'BS-F1');

    foreach ($over as $key => $value) {
        $component->set($key, $value);
    }

    return $component->call('save');
}

dataset('banned product fields', [
    'name uz' => ['form.name.uz', 'Eng arzon skaner', 'form.name.uz'],
    'name ru' => ['form.name.ru', 'Сканер со скидкой', 'form.name.ru'],
    'name en' => ['form.name.en', 'The cheapest scanner', 'form.name.en'],
    'short ru' => ['form.short_description.ru', 'Скидка на сканер', 'form.short_description.ru'],
    'meta title en' => ['form.meta_title.en', 'Cheapest scanner', 'form.meta_title.en'],
    'meta description uz' => ['form.meta_description.uz', 'Chegirma bilan', 'form.meta_description.uz'],
    'model' => ['form.model', 'DS-скидка', 'form.model'],
    'description ru' => ['form.description.ru', '<p>Скидка 10</p>', 'form.description.ru'],
]);

it('rejects banned wording per field and locale in the admin form', function (string $key, string $text, string $errorKey) {
    fillProductForm(Livewire::actingAs(staffUser(Role::ContentManager))->test(Form::class), [$key => $text])
        ->assertHasErrors([$errorKey]);

    expect(Product::query()->count())->toBe(0);
})->with('banned product fields');

dataset('bypass attempts', [
    'tag split' => ['<p>ски<b>дка</b></p>'],
    'tag split en' => ['<p>dis<em>count</em></p>'],
    'numeric entity' => ['<p>&#1089;кидка</p>'],
    'zero width space' => ["<p>ски\u{200B}дка</p>"],
    'soft hyphen' => ["<p>che\u{00AD}apest</p>"],
]);

it('rejects bypass attempts in a description', function (string $html) {
    fillProductForm(Livewire::actingAs(staffUser(Role::ContentManager))->test(Form::class), ['form.description.uz' => $html])
        ->assertHasErrors(['form.description.uz']);

    expect(ProductTextRules::violatesDescription($html))->toBeTrue();
})->with('bypass attempts');

it('rejects tag-split and invisible-character names', function () {
    expect(ProductTextRules::violatesPlain('ски<b>дка</b>'))->toBeTrue()
        ->and(ProductTextRules::violatesPlain("ски\u{200B}дка"))->toBeTrue()
        ->and(BrandSafeText::violates("dis\u{200B}count"))->toBeTrue();
});

it('does not flag a banned word inside a stripped script and saves a clean product unchanged', function () {
    expect(ProductTextRules::violatesDescription('<p>Skaner</p><script>скидка</script>'))->toBeFalse();

    fillProductForm(Livewire::actingAs(staffUser(Role::ContentManager))->test(Form::class), [
        'form.description.uz' => '<p>Ishonchli skaner</p>',
        'form.model' => 'DS2208',
    ])->assertHasNoErrors();

    expect(Product::query()->sole()->getTranslation('description', 'uz'))->toBe('<p>Ishonchli skaner</p>');
});

it('rejects an unknown locale key sent to the form', function () {
    fillProductForm(Livewire::actingAs(staffUser(Role::ContentManager))->test(Form::class), ['form.name.xx' => 'Skaner'])
        ->assertHasErrors();

    expect(Product::query()->count())->toBe(0);
});

it('enforces the rule inside SaveProduct for every caller', function (array $over, string $errorKey) {
    try {
        app(SaveProduct::class)->handle(null, saveProductData($over));
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey);
        expect(json_encode($exception->errors(), JSON_UNESCAPED_UNICODE))->not->toContain('скидка')->not->toContain('cheapest');
    }

    expect(Product::query()->count())->toBe(0);
})->with([
    'name' => [['name' => ['uz' => 'Skaner', 'ru' => 'Сканер скидка']], 'name.ru'],
    'tag split name' => [['name' => ['uz' => 'ски<b>дка</b>']], 'name.uz'],
    'short' => [['short_description' => ['en' => 'cheapest']], 'short_description.en'],
    'meta' => [['meta_description' => ['uz' => 'arzon']], 'meta_description.uz'],
    'model' => [['model' => 'cheapest'], 'model'],
    'description entity' => [['description' => ['uz' => '<p>&#1089;кидка</p>']], 'description.uz'],
    'unknown locale' => [['name' => ['uz' => 'Skaner', 'xx' => 'Skaner']], 'name.xx'],
]);

it('rejects banned spec text in SaveProduct and accepts a percent range', function () {
    $category = Category::factory()->create();
    $attribute = Attribute::factory()->create(['type' => AttributeType::Text]);
    $spec = fn (string $text): array => [['attribute_id' => $attribute->id, 'text' => ['uz' => $text]]];

    expect(fn () => app(SaveProduct::class)->handle(null, saveProductData(['category_id' => $category->id]), $spec('−15%')))
        ->toThrow(ValidationException::class);

    app(SaveProduct::class)->handle(null, saveProductData(['category_id' => $category->id]), $spec('10-90%'));

    expect(Product::query()->count())->toBe(1);
});

it('rejects an unknown locale key in a spec text with validation.in and never echoes the key', function () {
    $category = Category::factory()->create();
    $attribute = Attribute::factory()->create(['type' => AttributeType::Text]);

    try {
        app(SaveProduct::class)->handle(null, saveProductData(['category_id' => $category->id]), [['attribute_id' => $attribute->id, 'text' => ['uz' => '203 dpi', '<b>xx' => '203 dpi']]]);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('specs.0.text.<b>xx')
            ->and($exception->errors()['specs.0.text.<b>xx'][0])->toBe(__('validation.in', ['attribute' => __('admin.products.specs.value')]))
            ->and(json_encode(array_values($exception->errors())))->not->toContain('xx');
    }

    expect(Product::query()->count())->toBe(0);
});

it('allows "point of sale" in the product context only and keeps sale wording blocked', function () {
    expect(ProductTextRules::violatesPlain('Point of sale terminal'))->toBeFalse()
        ->and(ProductTextRules::violatesPlain('Point-of-sale terminal'))->toBeFalse()
        ->and(ProductTextRules::violatesPlain('Point of sale! -20%'))->toBeTrue()
        ->and(ProductTextRules::violatesPlain('On sale'))->toBeTrue()
        ->and(BrandSafeText::violates('Point of sale terminal'))->toBeTrue();
});

dataset('product context violations', [
    'upper case percent' => ['POINT OF SALE 50%'],
    'percent off in its own sentence' => ['Point of sale terminal. 50% off'],
    'percent off' => ['point of sale 50% off'],
    'percent later in the sentence' => ['point of sale, up to 50% off'],
    'percent before' => ['50% point of sale'],
    'exclamation' => ['Big point of sale event!'],
    'fullwidth exclamation' => ["point of sale\u{FF01}"],
    'fullwidth percent' => ["point of sale \u{FF05}"],
    'double exclamation' => ["point of sale \u{203C}"],
    'fullwidth sale' => ["\u{FF53}\u{FF41}\u{FF4C}\u{FF45}"],
    'fullwidth skidka' => ["\u{FF33}\u{FF2B}\u{FF29}\u{FF24}\u{FF2B}\u{FF21}"],
    'fullwidth badge' => ["\u{2212}20\u{FF05}"],
    'percent range in the same sentence' => ['Point-of-sale terminal, 10-90% humidity'],
    'after-sales with discount word' => ['after-sales скидка'],
]);

it('rejects promo wording around the product allowances', function (string $text) {
    expect(ProductTextRules::violatesPlain($text))->toBeTrue()
        ->and(BrandSafeText::violates($text, BrandSafeText::PRODUCT))->toBeTrue();
})->with('product context violations');

dataset('product context accepted', [
    'pos terminal' => ['Point of sale terminal'],
    'percent in another sentence' => ['Point-of-sale terminal. Humidity 10-90%'],
    'spec range' => ['10-90%'],
    'after-sales service' => ['after-sales service included'],
    'sales receipt' => ['prints a sales receipt'],
]);

it('accepts legitimate POS and service wording in the product context', function (string $text) {
    expect(ProductTextRules::violatesPlain($text))->toBeFalse();
})->with('product context accepted');

it('keeps the product allowances out of the default context', function () {
    expect(BrandSafeText::violates('after-sales service included'))->toBeTrue()
        ->and(BrandSafeText::violates('prints a sales receipt'))->toBeTrue();
});

it('saves a product whose name uses POS wording through SaveProduct', function () {
    app(SaveProduct::class)->handle(null, saveProductData(['name' => ['uz' => 'Point of sale terminal', 'en' => 'Point-of-sale terminal. After-sales service included']]));

    expect(Product::query()->count())->toBe(1);
});

it('audits stored products read-only without printing text', function () {
    $product = Product::factory()->create();
    Product::query()->whereKey($product->id)->update(['name' => json_encode(['uz' => 'Skaner', 'ru' => 'Сканер со скидкой'], JSON_UNESCAPED_UNICODE)]);

    $this->artisan('catalog:brand-safe-audit')
        ->expectsOutputToContain("product {$product->id} name ru")
        ->doesntExpectOutputToContain('скидк')
        ->assertFailed();
});
