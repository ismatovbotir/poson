<?php

use App\Livewire\Site\Catalog\Index;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Support\Catalog\CatalogQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    openSearchIndexing();
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);
});

/**
 * A category with a filterable multiselect "interface" (10 options: USB, BT, RS232, + 7 more),
 * a boolean "wireless" and three products: P1 = USB, P2 = USB + BT, P3 = RS232.
 *
 * @return array<string, mixed>
 */
function matchModeFixture(): array
{
    $category = Category::factory()->create(['slug' => 'modes']);
    $interface = Attribute::factory()->multiselect(['USB', 'Bluetooth', 'RS232', 'O4', 'O5', 'O6', 'O7', 'O8', 'O9', 'O10'])->create(['code' => 'interface']);
    $wireless = Attribute::factory()->boolean()->create(['code' => 'wireless']);
    $category->attributes()->attach($interface->id, ['sort' => 1, 'is_required' => false, 'is_filterable' => true]);
    $category->attributes()->attach($wireless->id, ['sort' => 2, 'is_required' => false, 'is_filterable' => true]);

    $options = $interface->options()->orderBy('id')->get()->values();
    $p1 = Product::factory()->inCategory($category)->create(['name' => ['uz' => 'P1', 'ru' => 'P1', 'en' => 'P1']]);
    $p2 = Product::factory()->inCategory($category)->create(['name' => ['uz' => 'P2', 'ru' => 'P2', 'en' => 'P2']]);
    $p3 = Product::factory()->inCategory($category)->create(['name' => ['uz' => 'P3', 'ru' => 'P3', 'en' => 'P3']]);

    foreach ([[$p1, [0]], [$p2, [0, 1]], [$p3, [2]]] as [$product, $indexes]) {
        foreach ($indexes as $index) {
            ProductAttributeValue::factory()->for($product)->option($options[$index])->create();
        }
    }

    return compact('category', 'interface', 'wireless', 'options');
}

function matchModeNames(string $html): array
{
    preg_match_all('#<h3[^>]*>\s*<a href="[^"]+/product/[^"]+"[^>]*>([^<]+)</a>#', $html, $matches);
    sort($matches[1]);

    return $matches[1];
}

function matchModeGet(array $query): string
{
    return test()->get('/en/catalog/modes?'.http_build_query($query))->assertOk()->getContent();
}

function matchModeQuery(array $f, mixed $m = [], array $fixture = []): CatalogQuery
{
    return new CatalogQuery(category: $fixture['category'], attributeFilters: $f, matchModes: $m);
}

it('matches every ticked option by default and at least one in any mode', function () {
    $fx = matchModeFixture();
    [$usb, $bt, $rs] = [$fx['options'][0]->id, $fx['options'][1]->id, $fx['options'][2]->id];

    expect(matchModeNames(matchModeGet(['f' => ['interface' => [$usb, $bt]]])))->toBe(['P2'])
        ->and(matchModeNames(matchModeGet(['f' => ['interface' => [$usb, $bt]], 'm' => ['interface' => 'all']])))->toBe(['P2'])
        ->and(matchModeNames(matchModeGet(['f' => ['interface' => [$usb, $bt]], 'm' => ['interface' => 'any']])))->toBe(['P1', 'P2'])
        ->and(matchModeNames(matchModeGet(['f' => ['interface' => [$usb, $rs]], 'm' => ['interface' => 'any']])))->toBe(['P1', 'P2', 'P3'])
        ->and(matchModeNames(matchModeGet(['f' => ['interface' => [$usb, $rs]]])))->toBe([]);

    $sql = matchModeQuery(['interface' => [$usb, $bt]], [], $fx)->filteredQuery()->toSql();
    expect(substr_count($sql, 'exists'))->toBe(2);
});

it('caps ticked options at 8 per attribute with bounded queries', function () {
    $fx = matchModeFixture();
    $ids = $fx['options']->pluck('id')->all();
    $junk = range(900_000, 900_039);

    $query = matchModeQuery(['interface' => [...$ids, ...$junk]], ['interface' => 'any'], $fx);

    expect($query->attributeFilters()['interface']['options'])->toBe(array_slice($ids, 0, 8));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $query->facets();
    $query->paginate(24);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(20);
});

it('caps filter attributes at 12 per request', function () {
    $fx = matchModeFixture();
    $filters = [];

    foreach (range(1, 20) as $i) {
        $attribute = Attribute::factory()->boolean()->create(['code' => "flag{$i}"]);
        $fx['category']->attributes()->attach($attribute->id, ['sort' => 10 + $i, 'is_required' => false, 'is_filterable' => true]);
        $filters["flag{$i}"] = ['1'];
    }

    expect(matchModeQuery($filters, [], $fx)->attributeFilters())->toHaveCount(12);
});

it('sanitizes the m state: only any on filterable multiselect attributes', function (mixed $modes) {
    $fx = matchModeFixture();
    $ids = [$fx['options'][0]->id, $fx['options'][1]->id];

    $query = matchModeQuery(['interface' => $ids], $modes, $fx);

    expect($query->matchModes())->toBe([])
        ->and($query->attributeFilters()['interface']['mode'])->toBe('all');

    Livewire::withQueryParams(['f' => ['interface' => $ids], 'm' => $modes])
        ->test(Index::class, ['category' => $fx['category']])
        ->assertOk()
        ->assertDontSeeHtml('aria-pressed="true" aria-describedby="match-interface-any-hint"');

    matchModeGet(['f' => ['interface' => $ids], 'm' => $modes]);
})->with([
    'array value' => [['interface' => ['any']]],
    'bare list' => [['any']],
    'boolean attribute' => [['wireless' => 'any']],
    'brand' => [['brand' => 'any']],
    'unknown' => [['unknown' => 'any']],
    'uppercase value' => [['interface' => 'ALL']],
    'ANY uppercase' => [['interface' => 'ANY']],
    'nested' => [['interface' => ['x' => ['any']]]],
    'not an array' => ['any'],
]);

it('does not reflect m values and drops unknown codes', function () {
    $fx = matchModeFixture();
    $html = matchModeGet(['f' => ['interface' => [$fx['options'][0]->id]], 'm' => ['code' => '<script>alert(1)</script>']]);

    expect($html)->not->toContain('<script>alert(1)</script>');
});

it('ignores foreign, non-numeric and non-array option filters', function () {
    $fx = matchModeFixture();
    $other = Attribute::factory()->multiselect(['Zed'])->create(['code' => 'other']);
    $foreign = $other->options()->first()->id;

    foreach ([['interface' => [$foreign]], ['interface' => ['abc', '1e3', '-1']], ['interface' => 'x'], ['interface' => [['x']]]] as $f) {
        expect(matchModeNames(matchModeGet(['f' => $f])))->toBe(['P1', 'P2', 'P3']);
    }

    matchModeGet(['f' => 'junk']);
});

it('counts options by the match mode and greys the empty ones, never the ticked', function () {
    $fx = matchModeFixture();
    [$usb, $bt, $rs] = [$fx['options'][0]->id, $fx['options'][1]->id, $fx['options'][2]->id];

    // all: USB + BT ticked -> P2 only; RS232 would leave nobody -> 0.
    $facets = matchModeQuery(['interface' => [$usb, $bt]], [], $fx)->facets();
    $counts = collect($facets['attributes'][0]['options'])->keyBy('id');
    expect($counts[$usb]['count'])->toBe(1)
        ->and($counts[$bt]['count'])->toBe(1)
        ->and($counts[$rs]['count'])->toBe(0)
        ->and($counts[$rs]['disabled'])->toBeTrue()
        ->and($counts[$usb]['disabled'])->toBeFalse();

    // any: USB + BT ticked -> P1,P2; adding RS232 -> 3.
    $counts = collect(matchModeQuery(['interface' => [$usb, $bt]], ['interface' => 'any'], $fx)->facets()['attributes'][0]['options'])->keyBy('id');
    expect($counts[$rs]['count'])->toBe(3)
        ->and($counts[$usb]['count'])->toBe(2)
        ->and($counts[$bt]['count'])->toBe(1)
        ->and($counts[$rs]['disabled'])->toBeFalse();

    $html = matchModeGet(['f' => ['interface' => [$usb, $bt]]]);
    expect($html)->toContain(' disabled')->toContain('data-match-switch="interface"');
    expect(matchModeGet(['f' => ['interface' => [$usb]]]))->not->toContain('data-match-switch');
});

it('keeps facet query count independent of the number of ticked options', function (string $mode) {
    $fx = matchModeFixture();
    $ids = $fx['options']->pluck('id')->all();

    $run = function (array $ticked) use ($fx, $mode): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        matchModeQuery(['interface' => $ticked], ['interface' => $mode], $fx)->facets();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $run(array_slice($ids, 0, 1));

    expect($run(array_slice($ids, 0, 8)))->toBe($run(array_slice($ids, 0, 2)));
    expect($run(array_slice($ids, 0, 1)))->toBeLessThanOrEqual($run(array_slice($ids, 0, 8)));
})->with(['all', 'any']);

it('sets and clears the mode through the component', function () {
    $fx = matchModeFixture();
    [$usb, $bt] = [(string) $fx['options'][0]->id, (string) $fx['options'][1]->id];

    Livewire::test(Index::class, ['category' => $fx['category']])
        ->call('toggleValue', 'interface', $usb)
        ->call('toggleValue', 'interface', $bt)
        ->call('setMatchMode', 'interface', 'any')
        ->assertSet('modes', ['interface' => 'any'])
        ->call('setMatchMode', 'wireless', 'any')
        ->call('setMatchMode', 'nope', 'any')
        ->assertSet('modes', ['interface' => 'any'])
        ->call('removeFilter', 'option', 'interface', $bt)
        ->assertSet('modes', [])
        ->call('toggleValue', 'interface', $bt)
        ->call('setMatchMode', 'interface', 'any')
        ->call('removeFilter', 'attribute', 'interface')
        ->assertSet('f', [])
        ->assertSet('modes', [])
        ->call('toggleValue', 'interface', $usb)
        ->call('toggleValue', 'interface', $bt)
        ->call('setMatchMode', 'interface', 'any')
        ->call('resetFilters')
        ->assertSet('modes', [])
        ->assertSet('f', []);
});

it('puts the mode in pager links, keeps noindex and a clean canonical and hreflang', function () {
    $fx = matchModeFixture();
    $f = ['interface' => [$fx['options'][0]->id, $fx['options'][1]->id]];

    expect(matchModeQuery($f, ['interface' => 'any'], $fx)->queryParameters()['m'])->toBe(['interface' => 'any'])
        ->and(matchModeQuery($f, [], $fx)->queryParameters())->not->toHaveKey('m');

    $html = matchModeGet(['f' => $f, 'm' => ['interface' => 'any']]);

    expect($html)->toContain('<meta name="robots" content="noindex,follow">')
        ->toContain('<link rel="canonical" href="'.url('/en/catalog/modes').'">')
        ->toContain('hreflang="ru" href="'.url('/ru/catalog/modes').'"')
        ->not->toContain('rel="alternate" hreflang="ru" href="'.url('/ru/catalog/modes').'?');
});

/**
 * Printers: P1 (Zebra, LAN, 203 dpi), P2 (Zebra, USB, 300 dpi), P3 (Honeywell, LAN + USB, 203 dpi).
 *
 * @return array<string, mixed>
 */
function facetLinkFixture(): array
{
    $category = Category::factory()->create(['slug' => 'printers']);
    $connect = Attribute::factory()->multiselect(['LAN', 'USB'])->create(['code' => 'connect']);
    $dpi = Attribute::factory()->select(['203', '300', '600'])->create(['code' => 'dpi']);
    $category->attributes()->attach($connect->id, ['sort' => 1, 'is_required' => false, 'is_filterable' => true]);
    $category->attributes()->attach($dpi->id, ['sort' => 2, 'is_required' => false, 'is_filterable' => true]);
    $zebra = Brand::factory()->create(['slug' => 'zebra']);
    $honeywell = Brand::factory()->create(['slug' => 'honeywell']);
    $c = $connect->options()->orderBy('id')->get()->values();
    $d = $dpi->options()->orderBy('id')->get()->values();

    $rows = [[$zebra, [0], 0], [$zebra, [1], 1], [$honeywell, [0, 1], 0], [$honeywell, [1], 2]];
    foreach ($rows as [$brand, $conn, $res]) {
        $product = Product::factory()->inCategory($category)->for($brand)->create();
        foreach ($conn as $i) {
            ProductAttributeValue::factory()->for($product)->option($c[$i])->create();
        }
        ProductAttributeValue::factory()->for($product)->option($d[$res])->create();
    }

    return compact('category', 'c', 'd');
}

it('lets every facet group react to the other active filters', function () {
    $fx = facetLinkFixture();
    $facets = fn (array $f) => (new CatalogQuery(category: $fx['category'], attributeFilters: $f))->facets();
    $dpi = fn (array $facets) => collect($facets['attributes'])->firstWhere('code', 'dpi')['options'];

    // Nothing ticked: all three resolutions are available.
    expect(collect($dpi($facets([])))->pluck('disabled')->all())->toBe([false, false, false]);

    // LAN ticked: no LAN printer is 600 dpi -> that option is greyed, ticked ones never.
    $withLan = collect($dpi($facets(['connect' => [$fx['c'][0]->id]])))->keyBy('label');
    expect($withLan['600']['disabled'])->toBeTrue()
        ->and($withLan['600']['count'])->toBe(0)
        ->and($withLan['203']['count'])->toBe(2)
        ->and($withLan['300']['disabled'])->toBeTrue();

    // A ticked option is never disabled, even with zero results.
    $ticked = collect($dpi($facets(['connect' => [$fx['c'][0]->id], 'dpi' => [$fx['d'][2]->id]])))->keyBy('label');
    expect($ticked['600']['disabled'])->toBeFalse();

    // Brand counts drop with the LAN filter; 300 dpi leaves only Zebra.
    $brands = collect($facets(['dpi' => [$fx['d'][1]->id]])['brands'])->keyBy('slug');
    expect($brands['zebra']['count'])->toBe(1)
        ->and($brands['honeywell']['disabled'])->toBeTrue();
    $brands = collect($facets(['connect' => [$fx['c'][0]->id]])['brands'])->keyBy('slug');
    expect($brands['zebra']['count'])->toBe(1)->and($brands['honeywell']['count'])->toBe(1);

    // Rendered page greys the empty option.
    expect(matchModeGetOn('printers', ['f' => ['connect' => [$fx['c'][0]->id]]]))->toContain(' disabled');
});

function matchModeGetOn(string $slug, array $query): string
{
    return test()->get("/en/catalog/{$slug}?".http_build_query($query))->assertOk()->getContent();
}

it('keeps the facet query count independent of the ticked options with linked counts', function () {
    $fx = matchModeFixture();
    $ids = $fx['options']->pluck('id')->all();
    $count = function (array $ticked) use ($fx): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new CatalogQuery(category: $fx['category'], attributeFilters: ['interface' => $ticked]))->facets();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(array_slice($ids, 0, 1)); // warms the caches
    $eight = $count(array_slice($ids, 0, 8));

    expect($eight)->toBe($count(array_slice($ids, 0, 2)))->and($eight)->toBe($count(array_slice($ids, 0, 1)));
});

it('refuses a 9th ticked option in one attribute and disables the rest', function () {
    $fx = matchModeFixture();
    $ids = $fx['options']->pluck('id')->map(fn ($id) => (string) $id)->all();

    $component = Livewire::test(Index::class, ['category' => $fx['category']]);

    foreach (array_slice($ids, 0, 9) as $id) {
        $component->call('toggleValue', 'interface', $id);
    }

    $component->assertSet('f', ['interface' => array_slice($ids, 0, 8)]);

    // Unticking still works at the cap.
    $component->call('toggleValue', 'interface', $ids[0])->assertSet('f', ['interface' => array_slice($ids, 1, 7)]);
});

it('uses the admin default mode and lets the visitor deviate only when the switch is on', function () {
    $fx = matchModeFixture();
    $ids = [$fx['options'][0]->id, $fx['options'][1]->id];
    $fx['interface']->update(['filter_match' => 'any']);

    $names = fn (array $m) => matchModeNames(matchModeGet(['f' => ['interface' => $ids], 'm' => $m]));

    // Default any; m=all deviates; m=any equals the default and is stripped.
    expect($names([]))->toBe(['P1', 'P2'])
        ->and($names(['interface' => 'all']))->toBe(['P2'])
        ->and($names(['interface' => 'any']))->toBe(['P1', 'P2'])
        ->and(matchModeQuery(['interface' => $ids], ['interface' => 'any'], $fx)->queryParameters())->not->toHaveKey('m')
        ->and(matchModeQuery(['interface' => $ids], ['interface' => 'all'], $fx)->queryParameters()['m'])->toBe(['interface' => 'all']);

    // Switch off: the visitor value is ignored and no switch is rendered.
    $fx['interface']->update(['filter_match_switch' => false]);

    expect($names(['interface' => 'all']))->toBe(['P1', 'P2'])
        ->and(matchModeQuery(['interface' => $ids], ['interface' => 'all'], $fx)->matchModes())->toBe([])
        ->and(matchModeGet(['f' => ['interface' => $ids]]))->not->toContain('data-match-switch');

    // Counts follow the effective mode (default any: adding RS232 gives all three).
    $options = collect(matchModeQuery(['interface' => $ids], [], $fx)->facets()['attributes'][0]['options'])->keyBy('id');
    expect($options[$fx['options'][2]->id]['count'])->toBe(3);
});

it('shows the effective mode as the switch state and keeps the canonical clean', function () {
    $fx = matchModeFixture();
    $fx['interface']->update(['filter_match' => 'any']);
    $ids = [$fx['options'][0]->id, $fx['options'][1]->id];

    $html = matchModeGet(['f' => ['interface' => $ids], 'm' => ['interface' => ['x']]]);

    expect($html)->toMatch('#aria-pressed="true"\s+aria-describedby="match-interface-any-hint"#')
        ->toContain('<link rel="canonical" href="'.url('/en/catalog/modes').'">');
});
