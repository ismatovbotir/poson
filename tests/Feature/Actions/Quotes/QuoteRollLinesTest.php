<?php

use App\Actions\Catalog\SaveRollType;
use App\Actions\Quotes\CreateQuote;
use App\Actions\Quotes\DuplicateQuote;
use App\Actions\Quotes\GenerateQuotePdf;
use App\Actions\Quotes\SaveQuoteItems;
use App\Actions\Quotes\UpdateQuote;
use App\Data\Quotes\QuoteData;
use App\Data\Quotes\QuoteItemData;
use App\Enums\PriceLevel;
use App\Enums\ProductUnit;
use App\Enums\Role;
use App\Livewire\Admin\Quotes\Form;
use App\Models\Attribute;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RollType;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A label product 58×40 mm at 12 / 11 / 10 soʻm per label in 500 and 1000 label rolls (core 40 mm).
 *
 * @return array{0: Product, 1: RollType, 2: RollType}
 */
function quoteLabelProduct(): array
{
    $small = RollType::factory()->size(500, '40.0')->create();
    $large = RollType::factory()->size(1000, '40.0')->create();
    $product = Product::factory()->perLabel(12)->withRollTypes([$large, $small])->create(['min_qty' => 4, 'name' => ['uz' => 'Termoyorliqlar', 'ru' => 'Термоэтикетки', 'en' => 'Labels']]);

    ProductAttributeValue::factory()->for($product)->for(Attribute::factory()->number()->create(['code' => 'label_width']))->number(58)->create();
    ProductAttributeValue::factory()->for($product)->for(Attribute::factory()->number()->create(['code' => 'label_height']))->number(40)->create();

    return [$product, $small, $large];
}

/**
 * @param  list<QuoteItemData>  $items
 */
function rollQuote(array $items, PriceLevel $level = PriceLevel::Retail, string $locale = 'en'): Quote
{
    $customer = Customer::factory()->priceLevel($level)->create();

    return app(CreateQuote::class)->handle(new QuoteData(customerId: $customer->id, items: $items, locale: $locale), staffUser());
}

/**
 * @return array<string, list<string>>
 */
function rollQuoteErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('prices a label line per roll at the customer level and snapshots the roll', function () {
    [$product, $small] = quoteLabelProduct();

    $quote = rollQuote([new QuoteItemData(productId: $product->id, qty: 10, rollTypeId: $small->id)], PriceLevel::Dealer);
    $item = $quote->items()->sole();

    expect($item->qty)->toBe(10)
        ->and($item->unit)->toBe(ProductUnit::Roll)
        ->and($item->price_per_label)->toBe(1_100)
        ->and($item->price)->toBe(1_100 * 500)
        ->and($item->total)->toBe(1_100 * 500 * 10)
        ->and($item->roll_type_id)->toBe($small->id)
        ->and($item->labels_per_roll)->toBe(500)
        ->and($item->core_diameter_mm)->toBe('40.0')
        ->and($item->label_size)->toBe('58×40')
        ->and($quote->fresh()->total)->toBe(5_500_000)
        ->and($quote->fresh()->vat_amount)->toBe(Quote::vatOf(5_500_000));
});

it('takes the edited per-label price and keeps the invariant on later saves', function () {
    [$product, $small] = quoteLabelProduct();
    $quote = rollQuote([new QuoteItemData(productId: $product->id, qty: 2, rollTypeId: $small->id, pricePerLabel: 900)]);
    $item = $quote->items()->sole();

    expect($item->price)->toBe(450_000)->and($item->total)->toBe(900_000);

    app(SaveQuoteItems::class)->handle($quote, [new QuoteItemData(itemId: $item->id, qty: 3, pricePerLabel: 1_000)]);

    expect($item->fresh())->price_per_label->toBe(1_000)->price->toBe(500_000)->total->toBe(1_500_000)
        ->and($quote->fresh()->total)->toBe(1_500_000);

    // A per-roll "price" sent for a roll line is ignored: the per-label price stays the source of truth.
    app(SaveQuoteItems::class)->handle($quote, [new QuoteItemData(itemId: $item->id, qty: 3, price: 1)]);

    expect($item->fresh())->price_per_label->toBe(1_000)->price->toBe(500_000);
});

it('rejects a label line without an offered roll type, tiyin per label and a too high roll price', function () {
    [$product, $small] = quoteLabelProduct();
    $inactive = RollType::factory()->inactive()->create();
    $product->rollTypes()->attach($inactive->id, ['sort' => 9]);
    $foreign = RollType::factory()->create();

    expect(rollQuoteErrors(fn () => rollQuote([new QuoteItemData(productId: $product->id, qty: 1)])))->toHaveKey('items.0.roll_type_id')
        ->and(rollQuoteErrors(fn () => rollQuote([new QuoteItemData(productId: $product->id, qty: 1, rollTypeId: $inactive->id)])))->toHaveKey('items.0.roll_type_id')
        ->and(rollQuoteErrors(fn () => rollQuote([new QuoteItemData(productId: $product->id, qty: 1, rollTypeId: $foreign->id)])))->toHaveKey('items.0.roll_type_id')
        ->and(rollQuoteErrors(fn () => rollQuote([new QuoteItemData(productId: $product->id, qty: 1, rollTypeId: $small->id, pricePerLabel: 1_250)])))->toHaveKey('items.0.price_per_label')
        ->and(rollQuoteErrors(fn () => rollQuote([new QuoteItemData(productId: $product->id, qty: 1, rollTypeId: $small->id, pricePerLabel: SaveQuoteItems::MAX_PRICE)])))->toHaveKey('items.0.price')
        ->and(Quote::query()->count())->toBe(0);
});

it('keeps the snapshots when the product or the roll type changes later', function () {
    [$product, $small] = quoteLabelProduct();
    $quote = rollQuote([new QuoteItemData(productId: $product->id, qty: 5, rollTypeId: $small->id)]);
    $before = $quote->items()->sole()->only(['price', 'total', 'price_per_label', 'labels_per_roll', 'core_diameter_mm', 'label_size', 'roll_type_id']);

    $product->update(['price' => 9_900]);
    app(SaveRollType::class)->handle($small, ['labels_per_roll' => 750, 'core_diameter_mm' => '76', 'is_active' => false, 'sort' => null]);
    app(UpdateQuote::class)->handle($quote, new QuoteData(customerId: $quote->customer_id, items: [new QuoteItemData(itemId: $quote->items()->sole()->id, qty: 5)], validUntil: $quote->valid_until->toDateString()), staffUser());

    expect($quote->items()->sole()->only(array_keys($before)))->toBe($before);
});

it('duplicates roll lines with their roll, falling back to a free-text roll line', function () {
    [$product, $small, $large] = quoteLabelProduct();
    $quote = rollQuote([
        new QuoteItemData(productId: $product->id, qty: 4, rollTypeId: $small->id, pricePerLabel: 900),
        new QuoteItemData(productId: $product->id, qty: 6, rollTypeId: $large->id),
    ]);

    // The large roll is no longer offered: that line is copied as free text with its roll columns.
    $large->update(['is_active' => false]);

    $copy = app(DuplicateQuote::class)->handle($quote, staffUser());
    [$first, $second] = $copy->items()->orderBy('sort')->get()->all();

    expect($first->only(['product_id', 'roll_type_id', 'labels_per_roll', 'core_diameter_mm', 'label_size', 'qty', 'price_per_label', 'price']))->toBe([
        'product_id' => $product->id,
        'roll_type_id' => $small->id,
        'labels_per_roll' => 500,
        'core_diameter_mm' => '40.0',
        'label_size' => '58×40',
        'qty' => 4,
        // Re-priced at today's level price.
        'price_per_label' => 1_200,
        'price' => 600_000,
    ])->and($second->only(['product_id', 'roll_type_id', 'labels_per_roll', 'core_diameter_mm', 'label_size', 'qty', 'price_per_label', 'price', 'total', 'name']))->toBe([
        'product_id' => null,
        'roll_type_id' => $large->id,
        'labels_per_roll' => 1000,
        'core_diameter_mm' => '40.0',
        'label_size' => '58×40',
        'qty' => 6,
        'price_per_label' => 1_200,
        'price' => 1_200_000,
        'total' => 7_200_000,
        'name' => 'Labels',
    ]);
});

it('carries the lead roll into a quote created from the lead', function () {
    [$product, $small] = quoteLabelProduct();
    $customer = Customer::factory()->create();
    $lead = Lead::factory()->forCustomer($customer)->create();
    LeadItem::factory()->for($lead)->create(['product_id' => $product->id, 'qty' => 7, 'roll_type_id' => $small->id, 'labels_per_roll' => 500, 'core_diameter_mm' => '40.0']);

    $quote = app(CreateQuote::class)->handle(new QuoteData(customerId: $customer->id, leadId: $lead->id, items: null), staffUser());

    expect($quote->items()->sole())->roll_type_id->toBe($small->id)->qty->toBe(7)->price->toBe(600_000);
});

it('prints the roll line and the per-label price in the quote locale', function () {
    [$product, $small] = quoteLabelProduct();
    $quote = rollQuote([new QuoteItemData(productId: $product->id, qty: 10, rollTypeId: $small->id)], locale: 'en');

    $html = app(GenerateQuotePdf::class)->html($quote);

    // The Qty / Unit columns already say "10 roll": the roll line leaves the rolls out.
    expect($html)->toContain('<span data-roll-line>58×40 mm, 500 labels/roll, core 40 mm</span>')
        ->not->toContain('× 10 rolls')
        ->toContain("(12\u{00A0}UZS / label)")
        ->toContain('roll');

    $ru = app(GenerateQuotePdf::class)->html($quote->fresh(), 'ru');

    expect($ru)->toContain('58×40 мм, 500 этикеток/рулон, втулка 40 мм</span>')
        ->toContain("(12\u{00A0}сум / этикетка)");

    expect(QuoteItem::query()->sole()->rollDescription('uz'))->toBe('58×40 mm, 500 yorliq/rulon, gilza 40 mm × 10 rulon');
});

it('adds a label product in the quote form as a roll line with the first roll type and warns below the minimum', function () {
    [$product, $small, $large] = quoteLabelProduct();
    $customer = Customer::factory()->priceLevel(PriceLevel::Wholesale)->create();

    $component = Livewire::actingAs(staffUser(Role::SalesManager))
        ->test(Form::class)
        ->call('selectCustomer', $customer->id)
        ->call('addProduct', $product->id)
        ->assertSet('form.items.0.roll_type_id', $large->id)
        ->assertSet('form.items.0.labels_per_roll', 1000)
        ->assertSet('form.items.0.price_per_label', '10')
        ->assertSet('form.items.0.qty', 4)
        ->assertSee('data-roll-type-select', false)
        ->assertDontSee('data-below-min', false)
        ->set('form.items.0.roll_type_id', $small->id)
        ->assertSet('form.items.0.labels_per_roll', 500)
        ->set('form.items.0.qty', 2)
        ->assertSee('data-below-min', false)
        ->call('save')
        ->assertHasNoErrors();

    $item = QuoteItem::query()->sole();

    expect($item)->roll_type_id->toBe($small->id)->qty->toBe(2)->price_per_label->toBe(1_000)->price->toBe(500_000);

    Livewire::actingAs(staffUser())
        ->test(Form::class, ['quote' => $item->quote])
        ->assertSet('form.items.0.price_per_label', '10')
        ->assertSee('data-roll-line', false)
        ->set('form.items.0.price_per_label', '11')
        ->call('save')
        ->assertHasNoErrors();

    expect($item->fresh())->price_per_label->toBe(1_100)->price->toBe(550_000)->total->toBe(1_100_000);
});

it('copies a roll line as free text when its roll type changed since the snapshot', function () {
    [$product, $small] = quoteLabelProduct();
    $quote = rollQuote([new QuoteItemData(productId: $product->id, qty: 4, rollTypeId: $small->id)]);
    $small->update(['labels_per_roll' => 750]);

    $copy = app(DuplicateQuote::class)->handle($quote, staffUser())->items()->sole();

    expect($copy->only(['product_id', 'roll_type_id', 'labels_per_roll', 'price_per_label', 'price']))->toBe([
        'product_id' => null,
        'roll_type_id' => $small->id,
        'labels_per_roll' => 500,
        'price_per_label' => 1_200,
        'price' => 600_000,
    ]);
});

it('ignores forged roll fields in the admin quote form', function () {
    [$product, $small, $large] = quoteLabelProduct();
    $plain = Product::factory()->create(['price' => 50_000_00, 'price_dealer' => null, 'price_wholesale' => null]);
    $foreign = RollType::factory()->create();
    $customer = Customer::factory()->create();

    // A roll type the product does not offer.
    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->call('selectCustomer', $customer->id)
        ->call('addProduct', $product->id)
        ->set('form.items.0.roll_type_id', $foreign->id)
        ->call('save')
        ->assertHasErrors(['form.items.0.roll_type_id']);

    // A label line disguised as a normal line still needs its roll.
    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->call('selectCustomer', $customer->id)
        ->call('addProduct', $product->id)
        ->set('form.items.0.labels_per_roll', null)
        ->set('form.items.0.price', '1')
        ->call('save')
        ->assertHasErrors(['form.items.0.roll_type_id']);

    // A forged labels count never reaches the line: the roll type decides.
    Livewire::actingAs(staffUser())
        ->test(Form::class)
        ->call('selectCustomer', $customer->id)
        ->call('addProduct', $product->id)
        ->set('form.items.0.labels_per_roll', 1)
        ->call('addProduct', $plain->id)
        ->set('form.items.1.labels_per_roll', 1000)
        ->set('form.items.1.roll_type_id', $large->id)
        ->set('form.items.1.price_per_label', '1')
        ->call('save')
        ->assertHasNoErrors();

    [$label, $normal] = QuoteItem::query()->orderBy('sort')->get()->all();

    expect($label->labels_per_roll)->toBe(1000)
        ->and($label->price)->toBe(1_200_000)
        ->and($normal->isRollLine())->toBeFalse()
        ->and($normal->roll_type_id)->toBeNull()
        ->and($normal->price)->toBe(50_000_00);
});
