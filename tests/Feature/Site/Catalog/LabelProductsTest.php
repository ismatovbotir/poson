<?php

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Events\LeadCreated;
use App\Livewire\Site\Catalog\RollPicker;
use App\Livewire\Site\LeadForm;
use App\Models\Category;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\RollType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * A published label product at 12 soʻm per label (Dealer 11, Wholesale 10), min 4 rolls,
 * in 1000 (first) and 500 label rolls on a 40 mm core.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: Product, 1: RollType, 2: RollType}
 */
function siteLabelProduct(array $attributes = []): array
{
    $large = RollType::factory()->size(1000, '40.0')->create();
    $small = RollType::factory()->size(500, '40.0')->create();
    $category = Category::factory()->create(['slug' => 'thermal-labels']);

    $product = Product::factory()->perLabel(12)->inCategory($category)->withRollTypes([$large, $small])->create([
        'slug' => 'labels-58x40',
        'sku' => 'LBL-58X40',
        'min_qty' => 4,
        'name' => ['uz' => 'Termoyorliqlar 58×40', 'ru' => 'Термоэтикетки 58×40', 'en' => 'Labels 58×40'],
        ...$attributes,
    ]);

    return [$product, $large, $small];
}

it('shows the Retail price per label on the card and the product page, never the other levels', function () {
    siteLabelProduct();

    $listing = $this->get('/uz/catalog/thermal-labels')->assertOk()->getContent();
    $page = $this->get('/uz/product/labels-58x40')->assertOk()->getContent();

    expect($listing)->toContain("12\u{00A0}soʻm / yorliq")
        ->and($page)->toContain("12\u{00A0}soʻm / yorliq")
        ->and($page)->toContain('data-roll-picker')
        ->and($page)->not->toContain("11\u{00A0}soʻm")
        ->and($page)->not->toContain("10\u{00A0}soʻm / yorliq");

    expect($this->get('/ru/product/labels-58x40')->getContent())->toContain("12\u{00A0}сум / этикетка")
        ->and($this->get('/en/product/labels-58x40')->getContent())->toContain("12\u{00A0}UZS / label");

    expectNoBannedWording($page);
});

it('renders the label product page without lazy loading', function () {
    siteLabelProduct();
    Model::preventLazyLoading();

    $this->get('/uz/product/labels-58x40')->assertOk()->assertSee('data-roll-type-option', false);
});

it('describes the per-label price in JSON-LD with a UnitPriceSpecification', function () {
    siteLabelProduct();

    $product = collect(jsonLdBlocks($this->get('/en/product/labels-58x40')->getContent()))->firstWhere('@type', 'Product');

    expect($product['offers']['price'])->toBe('12.00')
        ->and($product['offers']['priceSpecification'])->toBe([
            '@type' => 'UnitPriceSpecification',
            'price' => '12.00',
            'priceCurrency' => 'UZS',
            'referenceQuantity' => [
                '@type' => 'QuantitativeValue',
                'value' => 1,
                'unitCode' => 'H87',
                'unitText' => 'label',
            ],
        ]);

    $ru = collect(jsonLdBlocks($this->get('/ru/product/labels-58x40')->getContent()))->firstWhere('@type', 'Product');

    expect($ru['offers']['priceSpecification']['referenceQuantity']['unitText'])->toBe('этикетка');
});

it('preselects the first roll type and the minimum, and shows the per-roll price and total', function () {
    [$product, $large, $small] = siteLabelProduct();

    Livewire::test(RollPicker::class, ['productId' => $product->id])
        ->assertSet('rollTypeId', $large->id)
        ->assertSet('rolls', 4)
        ->assertSeeHtml("12\u{00A0}000\u{00A0}soʻm")
        ->assertSeeHtml("48\u{00A0}000\u{00A0}soʻm")
        ->assertSee('Minimal buyurtma: 4 rulon')
        ->call('decrement')
        ->assertSet('rolls', 4)
        ->set('rollTypeId', $small->id)
        ->assertDispatched('roll-selected', productId: $product->id, rollTypeId: $small->id, rolls: 4)
        ->call('increment')
        ->assertSet('rolls', 5)
        ->assertSeeHtml("30\u{00A0}000\u{00A0}soʻm")
        ->set('rolls', 0)
        ->assertSet('rolls', 4)
        ->set('rolls', 1_000_000)
        ->assertSet('rolls', RollPicker::MAX_ROLLS)
        ->set('rollTypeId', RollType::factory()->create()->id)
        ->assertSet('rollTypeId', $large->id);
});

it('shows no amounts when the label price is on request', function () {
    [$product] = siteLabelProduct(['price' => null, 'price_dealer' => null, 'price_wholesale' => null]);

    Livewire::test(RollPicker::class, ['productId' => $product->id])
        ->assertSee('data-roll-on-request', false)
        ->assertDontSee('data-roll-total', false);
});

it('sends the chosen roll with the quote request and snapshots it on the lead item', function () {
    Event::fake([LeadCreated::class]);
    [$product, $large, $small] = siteLabelProduct();

    $component = Livewire::test(LeadForm::class, ['productId' => $product->id])
        ->assertSet('rollTypeId', $large->id)
        ->assertSet('rolls', 4)
        ->dispatch('roll-selected', productId: $product->id, rollTypeId: $small->id, rolls: 9)
        ->assertSee('500 yorliq/rulon, gilza 40 mm × 9 rulon')
        ->set('name', 'Aziz')
        ->set('phone', '90 123 45 67');

    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    $item = LeadItem::query()->sole();

    expect($item->qty)->toBe(9)
        ->and($item->roll_type_id)->toBe($small->id)
        ->and($item->labels_per_roll)->toBe(500)
        ->and($item->core_diameter_mm)->toBe('40.0')
        ->and($item->rollName('en'))->toBe('500 labels/roll, core 40 mm');

    // The snapshot stays when the roll type changes later.
    $small->update(['labels_per_roll' => 750]);

    expect($item->fresh()->labels_per_roll)->toBe(500);
});

it('rejects a quote request with a roll type the product does not offer or too few rolls', function (Closure $tamper) {
    [$product] = siteLabelProduct();
    $this->mock(SubmitSiteLead::class)->shouldNotReceive('handle');

    $component = Livewire::test(LeadForm::class, ['productId' => $product->id])
        ->set('name', 'Aziz')
        ->set('phone', '90 123 45 67');
    $tamper($component);

    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('submit')->assertHasErrors('form')->assertSet('sent', false);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'foreign roll type' => [fn ($component) => $component->set('rollTypeId', RollType::factory()->create()->id)],
    'inactive roll type' => [fn ($component) => $component->set('rollTypeId', RollType::query()->where('labels_per_roll', 500)->value('id'))->tap(fn () => RollType::query()->where('labels_per_roll', 500)->update(['is_active' => false]))],
    'no roll type' => [fn ($component) => $component->set('rollTypeId', null)],
    'below the minimum' => [fn ($component) => $component->set('rolls', 3)],
    'above the maximum' => [fn ($component) => $component->set('rolls', RollPicker::MAX_ROLLS + 1)],
]);

it('passes the roll to SubmitSiteLead only for label products', function () {
    [$product, $large] = siteLabelProduct();

    $this->mock(SubmitSiteLead::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (SiteLeadData $data): bool => $data->productId === $product->id && $data->rollTypeId === $large->id && $data->rolls === 4)
        ->andReturn('L-TEST');

    $component = Livewire::test(LeadForm::class, ['productId' => $product->id])->set('name', 'Aziz')->set('phone', '90 123 45 67');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('submit')->assertHasNoErrors();
});

it('treats an emptied rolls input as the minimum', function () {
    [$product] = siteLabelProduct();

    Livewire::test(RollPicker::class, ['productId' => $product->id])
        ->set('rolls', 7)
        ->set('rolls', '')
        ->assertSet('rolls', 4)
        ->assertHasNoErrors()
        ->set('rolls', null)
        ->call('increment')
        ->assertSet('rolls', 5)
        ->set('rolls', null)
        ->call('decrement')
        ->assertSet('rolls', 4);
});

it('takes a quote request without a roll when the product has no active roll type left', function () {
    Event::fake([LeadCreated::class]);
    [$product] = siteLabelProduct();
    RollType::query()->update(['is_active' => false]);

    $component = Livewire::test(LeadForm::class, ['productId' => $product->id])
        ->assertSet('rollTypeId', null)
        ->set('name', 'Aziz')
        ->set('phone', '90 123 45 67');

    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    expect(LeadItem::query()->sole())
        ->product_id->toBe($product->id)
        ->roll_type_id->toBeNull()
        ->labels_per_roll->toBeNull()
        ->qty->toBe(1);
});

it('casts and clamps tampered non-numeric roll type and rolls values instead of failing', function () {
    [$product, $large] = siteLabelProduct();

    $component = Livewire::test(RollPicker::class, ['productId' => $product->id])
        ->set('rollTypeId', 'abc')
        ->assertSet('rollTypeId', $large->id)
        ->set('rollTypeId', ['x'])
        ->assertSet('rollTypeId', $large->id)
        ->set('rolls', 'lots')
        ->assertSet('rolls', 4)
        ->set('rolls', ['x' => 1])
        ->assertSet('rolls', 4)
        ->set('rolls', '6.7')
        ->assertSet('rolls', 6)
        ->set('rolls', '1e30')
        ->assertSet('rolls', RollPicker::MAX_ROLLS)
        ->assertHasNoErrors();

    expect($component->get('rolls'))->toBeInt()
        ->and($component->get('rollTypeId'))->toBe($large->id);
});
