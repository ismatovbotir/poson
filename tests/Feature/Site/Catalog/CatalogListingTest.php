<?php

use App\Enums\StockStatus;
use App\Livewire\Site\Catalog\Index;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Money;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

// These tests cover the site open for indexing (plan 15: closed by default).
beforeEach(function () {
    openSearchIndexing();
});

/**
 * A scanners tree with filterable attributes and four products:
 * A (Zebra, USB + Bluetooth, 1 000 000 soʻm, in stock, range 30 m, wireless, featured),
 * B (Honeywell, USB, 2 000 000 soʻm, on order, range 10 m),
 * C (Zebra, RS232, price on request, in the root category), and an inactive D.
 *
 * @return array<string, mixed>
 */
function listingFixture(): array
{
    $root = Category::factory()->create(['slug' => 'scanners', 'name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
    $child = Category::factory()->child($root)->create(['slug' => 'handheld', 'name' => ['uz' => 'Qoʻl skanerlari', 'ru' => 'Ручные', 'en' => 'Handheld']]);

    $interface = Attribute::factory()->multiselect(['USB', 'Bluetooth', 'RS232', 'Wi-Fi'])->create(['code' => 'interface', 'name' => ['uz' => 'Interfeys', 'ru' => 'Интерфейс', 'en' => 'Interface']]);
    $range = Attribute::factory()->number('m')->create(['code' => 'range', 'name' => ['uz' => 'Masofa', 'ru' => 'Дальность', 'en' => 'Range']]);
    $wireless = Attribute::factory()->boolean()->create(['code' => 'wireless', 'name' => ['uz' => 'Simsiz', 'ru' => 'Беспроводной', 'en' => 'Wireless']]);
    $color = Attribute::factory()->select(['Black', 'Grey'])->create(['code' => 'color', 'name' => ['uz' => 'Rang', 'ru' => 'Цвет', 'en' => 'Colour']]);

    $root->attributes()->attach($interface->id, ['sort' => 1, 'is_required' => false, 'is_filterable' => true]);
    $child->attributes()->attach($range->id, ['sort' => 1, 'is_required' => false, 'is_filterable' => true]);
    $child->attributes()->attach($wireless->id, ['sort' => 2, 'is_required' => false, 'is_filterable' => true]);
    $child->attributes()->attach($color->id, ['sort' => 3, 'is_required' => false, 'is_filterable' => false]);

    $zebra = Brand::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);
    Brand::factory()->inactive()->create(['name' => 'Hidden Brand', 'slug' => 'hidden-brand']);
    $honeywell = Brand::factory()->create(['name' => 'Honeywell', 'slug' => 'honeywell']);
    $option = fn (string $value): AttributeOption => $interface->options()->get()->first(fn (AttributeOption $option) => $option->getTranslation('value', 'uz') === $value);

    $a = Product::factory()->inCategory($child)->for($zebra)->create(['price' => 100_000_000, 'stock_status' => StockStatus::InStock, 'is_featured' => true, 'name' => ['uz' => 'Alpha uz', 'ru' => 'Alpha ru', 'en' => 'Alpha en']]);
    $b = Product::factory()->inCategory($child)->for($honeywell)->create(['price' => 200_000_000, 'stock_status' => StockStatus::OnOrder, 'name' => ['uz' => 'Bravo uz', 'ru' => 'Bravo ru', 'en' => 'Bravo en']]);
    $c = Product::factory()->inCategory($root)->for($zebra)->priceOnRequest()->create(['name' => ['uz' => 'Charlie uz', 'ru' => 'Charlie ru', 'en' => 'Charlie en']]);
    $d = Product::factory()->inCategory($child)->for($zebra)->inactive()->create(['name' => ['uz' => 'Delta uz', 'ru' => 'Delta ru', 'en' => 'Delta en']]);

    ProductAttributeValue::factory()->for($a)->option($option('USB'))->key()->create(['sort' => 1]);
    ProductAttributeValue::factory()->for($a)->option($option('Bluetooth'))->key()->create(['sort' => 1]);
    ProductAttributeValue::factory()->for($a)->number(30)->key()->create(['attribute_id' => $range->id, 'sort' => 2]);
    ProductAttributeValue::factory()->for($a)->boolean()->create(['attribute_id' => $wireless->id]);
    ProductAttributeValue::factory()->for($b)->option($option('USB'))->create();
    ProductAttributeValue::factory()->for($b)->number(10)->create(['attribute_id' => $range->id]);
    ProductAttributeValue::factory()->for($b)->boolean(false)->create(['attribute_id' => $wireless->id]);
    ProductAttributeValue::factory()->for($c)->option($option('RS232'))->create();
    ProductAttributeValue::factory()->for($d)->option($option('USB'))->create();

    return compact('root', 'child', 'interface', 'range', 'wireless', 'color', 'zebra', 'honeywell', 'a', 'b', 'c', 'd') + [
        'usb' => $option('USB'), 'bluetooth' => $option('Bluetooth'), 'rs232' => $option('RS232'), 'wifi' => $option('Wi-Fi'),
    ];
}

/**
 * Names of the product cards on a listing page, in order.
 *
 * @return list<string>
 */
function listedNames(string $html): array
{
    preg_match_all('#<h3[^>]*>\s*<a href="[^"]+/product/[^"]+"[^>]*>([^<]+)</a>#', $html, $matches);

    return array_map(fn (string $name): string => html_entity_decode(trim($name)), $matches[1]);
}

/**
 * @param  array<string, mixed>  $query
 */
function listingUrl(string $locale, ?string $slug = null, array $query = []): string
{
    $path = $slug !== null ? "/{$locale}/catalog/{$slug}" : "/{$locale}/catalog";

    return $query === [] ? $path : $path.'?'.http_build_query($query);
}

it('lists the published products of a category and its subcategories', function (string $locale) {
    $fixture = listingFixture();
    Product::factory()->inCategory($fixture['child'])->create(['name' => ['uz' => 'Trashed', 'ru' => 'Trashed', 'en' => 'Trashed']])->delete();
    Product::factory()->inCategory(Category::factory()->child($fixture['root'])->inactive()->create())->create(['name' => ['uz' => 'Hidden', 'ru' => 'Hidden', 'en' => 'Hidden']]);
    Product::factory()->create(['name' => ['uz' => 'Elsewhere', 'ru' => 'Elsewhere', 'en' => 'Elsewhere']]);

    $html = $this->get(listingUrl($locale, 'scanners'))->assertOk()->getContent();

    expect(listedNames($html))->toEqualCanonicalizing(["Alpha {$locale}", "Bravo {$locale}", "Charlie {$locale}"])
        ->and($html)->toContain('<h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">'.e($fixture['root']->getTranslation('name', $locale)).'</h1>')
        ->and($html)->toContain('href="'.url("/{$locale}/catalog/handheld").'"');

    expect(listedNames($this->get(listingUrl($locale, 'handheld'))->assertOk()->getContent()))
        ->toEqualCanonicalizing(["Alpha {$locale}", "Bravo {$locale}"]);

    expect(listedNames($this->get(listingUrl($locale))->assertOk()->getContent()))
        ->toEqualCanonicalizing(["Alpha {$locale}", "Bravo {$locale}", "Charlie {$locale}", 'Elsewhere']);
})->with(['uz', 'ru', 'en']);

it('returns 404 for hidden or unknown categories', function () {
    $inactive = Category::factory()->inactive()->create();
    $underInactive = Category::factory()->child($inactive)->create();

    $this->get("/ru/catalog/{$inactive->slug}")->assertNotFound();
    $this->get("/ru/catalog/{$underInactive->slug}")->assertNotFound();
    $this->get('/ru/catalog/no-such-category')->assertNotFound();
});

it('filters by brand, stock and Retail price range', function (array $query, array $expected) {
    listingFixture();

    expect(listedNames($this->get(listingUrl('en', 'scanners', $query))->assertOk()->getContent()))
        ->toEqualCanonicalizing($expected);
})->with([
    'one brand' => [['brand' => ['zebra']], ['Alpha en', 'Charlie en']],
    'brands are OR-ed' => [['brand' => ['zebra', 'honeywell']], ['Alpha en', 'Bravo en', 'Charlie en']],
    'unknown brand ignored' => [['brand' => ['nope']], ['Alpha en', 'Bravo en', 'Charlie en']],
    'inactive brand ignored' => [['brand' => ['hidden-brand']], ['Alpha en', 'Bravo en', 'Charlie en']],
    'stock' => [['stock' => ['on_order']], ['Bravo en']],
    'price from (whole soʻm)' => [['price_min' => '1500000'], ['Bravo en']],
    'price up to, no price-on-request' => [['price_max' => '1 500 000'], ['Alpha en']],
    'brand AND stock' => [['brand' => ['zebra'], 'stock' => ['in_stock']], ['Alpha en', 'Charlie en']],
]);

it('filters by attributes: OR within an attribute, AND across attributes', function () {
    $fixture = listingFixture();
    $get = fn (array $query, string $slug = 'scanners'): array => listedNames($this->get(listingUrl('en', $slug, $query))->assertOk()->getContent());

    expect($get(['f' => ['interface' => [$fixture['usb']->id]]]))->toEqualCanonicalizing(['Alpha en', 'Bravo en'])
        ->and($get(['f' => ['interface' => [$fixture['usb']->id, $fixture['rs232']->id]]]))->toEqualCanonicalizing(['Alpha en', 'Bravo en', 'Charlie en'])
        ->and($get(['f' => ['interface' => [$fixture['bluetooth']->id]]]))->toBe(['Alpha en'])
        ->and($get(['f' => ['interface' => [$fixture['usb']->id], 'range' => ['min' => '20']]], 'handheld'))->toBe(['Alpha en'])
        ->and($get(['f' => ['range' => ['max' => '15']]], 'handheld'))->toBe(['Bravo en'])
        ->and($get(['f' => ['wireless' => ['1']]], 'handheld'))->toBe(['Alpha en'])
        // Attributes that aren't filters of the listing's category are ignored.
        ->and($get(['f' => ['range' => ['min' => '20']]]))->toEqualCanonicalizing(['Alpha en', 'Bravo en', 'Charlie en']);
});

it('offers only filterable attributes of the category and its ancestors, with base-set counts', function () {
    $fixture = listingFixture();

    $root = $this->get(listingUrl('en', 'scanners'))->assertOk()->getContent();
    $child = $this->get(listingUrl('en', 'handheld', ['brand' => ['honeywell']]))->assertOk()->getContent();

    expect($root)->toContain('data-filter="interface"')
        ->not->toContain('data-filter="range"')
        ->not->toContain('data-filter="color"')
        ->and($child)->toContain('data-filter="interface"')
        ->toContain('data-filter="range"')
        ->toContain('data-filter="wireless"')
        ->not->toContain('data-filter="color"');

    preg_match('#data-filter="interface".*?</fieldset>#s', $root, $interface);

    // USB: A and B; the inactive D doesn't count; Wi-Fi has no products and is hidden.
    expect($interface[0])->toMatch('#USB</span>\s*<em[^>]*>2</em>#')
        ->toMatch('#Bluetooth</span>\s*<em[^>]*>1</em>#')
        ->toMatch('#RS232</span>\s*<em[^>]*>1</em>#')
        ->not->toContain('Wi-Fi');

    // Counts come from the unfiltered base set, also with a brand filter on.
    preg_match('#data-filter="interface".*?</fieldset>#s', $child, $childInterface);
    expect($childInterface[0])->toMatch('#USB</span>\s*<em[^>]*>2</em>#');
});

it('sorts by popularity, Retail price (price on request last) and newness', function (string $sort, array $expected) {
    $fixture = listingFixture();
    $fixture['a']->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
    $fixture['b']->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
    $fixture['c']->forceFill(['created_at' => now()->subDay()])->saveQuietly();

    expect(listedNames($this->get(listingUrl('ru', 'scanners', ['sort' => $sort]))->assertOk()->getContent()))->toBe($expected);
})->with([
    'popular (featured first)' => ['popular', ['Alpha ru', 'Bravo ru', 'Charlie ru']],
    'price ascending' => ['price_asc', ['Alpha ru', 'Bravo ru', 'Charlie ru']],
    'price descending' => ['price_desc', ['Bravo ru', 'Alpha ru', 'Charlie ru']],
    'newest first' => ['new', ['Charlie ru', 'Bravo ru', 'Alpha ru']],
]);

it('paginates 24 products per page with a crawlable pager and a page canonical', function (string $locale) {
    $category = Category::factory()->create(['slug' => 'labels']);
    Product::factory()->count(25)->inCategory($category)->create();

    $first = $this->get("/{$locale}/catalog/labels")->assertOk()->getContent();
    $second = $this->get("/{$locale}/catalog/labels?page=2")->assertOk()->getContent();

    expect(substr_count($first, 'data-product-card'))->toBe(24)
        ->and($first)->toContain('href="'.url("/{$locale}/catalog/labels").'?page=2"')
        ->and($first)->toContain('<link rel="canonical" href="'.url("/{$locale}/catalog/labels").'">')
        ->and(substr_count($second, 'data-product-card'))->toBe(1)
        ->and($second)->toContain('<link rel="canonical" href="'.url("/{$locale}/catalog/labels").'?page=2">')
        ->and($second)->toContain('data-total="25"');
})->with(['uz', 'ru', 'en']);

it('keeps filters in the pager links', function () {
    $category = Category::factory()->create(['slug' => 'labels']);
    $brand = Brand::factory()->create(['slug' => 'godex']);
    Product::factory()->count(25)->inCategory($category)->for($brand)->create();

    $html = $this->get('/en/catalog/labels?'.http_build_query(['brand' => ['godex']]))->assertOk()->getContent();

    expect($html)->toContain('href="'.e(url('/en/catalog/labels').'?'.http_build_query(['brand' => ['godex'], 'page' => 2])).'"');
});

it('keeps any filtered or sorted listing out of the index, with a clean canonical', function (array $query, ?string $robots) {
    listingFixture();

    $html = $this->get(listingUrl('ru', 'scanners', $query))->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.url('/ru/catalog/scanners').'">');

    $robots === null
        ? expect($html)->not->toContain('<meta name="robots"')
        : expect($html)->toContain('<meta name="robots" content="'.$robots.'">');
})->with([
    'no filters' => [[], null],
    'one filter' => [['brand' => ['zebra']], 'noindex,follow'],
    'a price bound' => [['price_max' => '1000'], 'noindex,follow'],
    'two filter values' => [['brand' => ['zebra', 'honeywell']], 'noindex,follow'],
    'two filters' => [['brand' => ['zebra'], 'stock' => ['in_stock']], 'noindex,follow'],
    'a sort' => [['sort' => 'price_asc'], 'noindex,follow'],
]);

it('ignores malformed filter input', function () {
    listingFixture();

    $html = $this->get('/en/catalog/scanners?brand=zebra&stock[]=bogus&f=1&f[interface][]=abc&f[range][min]=x&sort=cheapest&price_min=abc&page=-1')
        ->assertOk()
        ->getContent();

    expect(listedNames($html))->toEqualCanonicalizing(['Alpha en', 'Bravo en', 'Charlie en']);
});

it('shows chips for active filters and an empty state with a reset', function () {
    $fixture = listingFixture();

    $html = $this->get(listingUrl('en', 'scanners', [
        'brand' => ['honeywell'],
        'stock' => ['out_of_stock'],
        'price_min' => '100',
        'f' => ['interface' => [$fixture['rs232']->id]],
    ]))->assertOk()->getContent();

    expect(substr_count($html, 'data-chip'))->toBe(4)
        ->and($html)->toContain('Honeywell')
        ->toContain(e(__('site.catalog.chip_price_min', ['amount' => Money::format(10_000)], 'en')))
        ->toContain('Interface: RS232')
        ->toContain('data-empty')
        ->toContain(__('site.catalog.empty_filtered', [], 'en'));
});

it('updates the filters through Livewire actions', function () {
    $fixture = listingFixture();
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);

    Livewire::test(Index::class, ['category' => $fixture['root']])
        ->assertSee(['Alpha en', 'Bravo en', 'Charlie en'])
        ->call('toggleBrand', 'honeywell')
        ->assertSet('brand', ['honeywell'])
        ->assertSee('Bravo en')
        ->assertDontSee('Alpha en')
        ->call('toggleBrand', 'honeywell')
        ->assertSet('brand', [])
        ->call('toggleValue', 'interface', (string) $fixture['rs232']->id)
        ->assertSet('f', ['interface' => [(string) $fixture['rs232']->id]])
        ->assertSee('Charlie en')
        ->assertDontSee('Bravo en')
        ->call('removeFilter', 'option', 'interface', (string) $fixture['rs232']->id)
        ->assertSet('f', [])
        ->set('priceMin', '1 500 000 soʻm')
        ->assertSet('priceMin', '1500000')
        ->assertSee('Bravo en')
        ->assertDontSee('Alpha en')
        ->call('toggleStock', 'in_stock')
        ->call('resetFilters')
        ->assertSet('priceMin', '')
        ->assertSet('stock', [])
        ->set('sort', 'price_desc')
        ->assertSeeInOrder(['Bravo en', 'Alpha en', 'Charlie en']);
});

it('shows the key specs and Retail price on the cards', function () {
    $fixture = listingFixture();

    $html = $this->get(listingUrl('ru', 'handheld'))->assertOk()->getContent();

    expect($html)->toContain('USB; Bluetooth · 30 '.__('catalog.units.m', [], 'ru'))
        ->toContain('1'.Money::NBSP.'000'.Money::NBSP.'000'.Money::NBSP.__('site.currency', [], 'ru'))
        ->toContain('href="'.url('/ru/product/'.$fixture['a']->slug).'#quote"');
});

it('renders listings without lazy loading or per-product queries', function () {
    $fixture = listingFixture();
    $this->get(listingUrl('ru', 'scanners'))->assertOk();
    $few = countQueries(fn () => $this->get(listingUrl('ru', 'scanners'))->assertOk());

    foreach (range(1, 8) as $index) {
        $product = Product::factory()->inCategory($fixture['child'])->create();
        ProductAttributeValue::factory()->for($product)->option($fixture['usb'])->key()->create();
        ProductAttributeValue::factory()->for($product)->number($index)->key()->create(['attribute_id' => $fixture['range']->id]);
    }

    $this->get(listingUrl('ru', 'scanners'))->assertOk();
    $many = countQueries(fn () => $this->get(listingUrl('ru', 'scanners'))->assertOk());

    expect($many)->toBe($few);
});

it('returns 404 for a page past the end', function () {
    listingFixture();

    $this->get('/en/catalog/scanners?page=2')->assertNotFound();
    $this->get('/en/catalog?page=99')->assertNotFound();
    $this->get('/en/catalog/scanners?page=1')->assertOk();
    $this->get('/en/catalog/scanners?'.http_build_query(['brand' => ['zebra'], 'stock' => ['out_of_stock']]))->assertOk()->assertSee('data-empty', false);
});

it('hides inactive brands from the brand filter, chips and cards', function () {
    $fixture = listingFixture();
    $fixture['honeywell']->update(['is_active' => false]);

    $html = $this->get(listingUrl('en', 'scanners', ['brand' => ['honeywell']]))->assertOk()->getContent();

    expect(listedNames($html))->toEqualCanonicalizing(['Alpha en', 'Bravo en', 'Charlie en'])
        ->and($html)->not->toContain('Honeywell')
        ->not->toContain('data-chip');
});

it('formats numbers by locale and shows ranges in chips', function () {
    $fixture = listingFixture();
    Product::factory()->count(1)->inCategory($fixture['child'])->create(['price' => 1_250_000_000]);

    $query = ['f' => ['range' => ['min' => '5', 'max' => '25.5']]];
    $ru = $this->get(listingUrl('ru', 'handheld', $query))->assertOk()->getContent();
    $en = $this->get(listingUrl('en', 'handheld', ['f' => ['range' => ['min' => '5']]]))->assertOk()->getContent();
    $uz = $this->get(listingUrl('uz', 'handheld', ['f' => ['range' => ['max' => '1250']]]))->assertOk()->getContent();

    expect($ru)->toContain('Дальность: 5–25,5 '.__('catalog.units.m', [], 'ru'))
        ->toContain('placeholder="12'.Money::NBSP.'500'.Money::NBSP.'000"')
        ->and($en)->toContain('Range: ≥ 5 m')
        ->toContain('placeholder="12,500,000"')
        ->and($uz)->toContain('Masofa: ≤ 1'.Money::NBSP.'250 m');
});

it('turns wrong input types into empty filters instead of errors', function () {
    $fixture = listingFixture();
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);

    Livewire::test(Index::class, ['category' => $fixture['root']])
        ->set('brand', 'zebra')
        ->assertSet('brand', [])
        ->set('priceMin', ['x' => 1])
        ->assertSet('priceMin', '')
        ->set('f', 'nonsense')
        ->assertSet('f', [])
        ->set('sort', ['price_asc'])
        ->assertSet('sort', 'popular')
        ->assertSee(['Alpha en', 'Bravo en', 'Charlie en']);
});

it('returns 404 on update requests once the category is hidden', function () {
    $fixture = listingFixture();
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);

    $component = Livewire::test(Index::class, ['category' => $fixture['root']]);
    $fixture['root']->update(['is_active' => false]);

    $component->call('toggleBrand', 'zebra')->assertStatus(404);
});
