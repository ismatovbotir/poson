<?php

use App\Livewire\Site\Search;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Card names on a results page.
 *
 * @return list<string>
 */
function searchResultNames(string $html): array
{
    preg_match_all('#<h3[^>]*>\s*<a href="[^"]+/product/[^"]+"[^>]*>([^<]+)</a>#', $html, $matches);

    return array_map(fn (string $name): string => html_entity_decode(trim($name)), $matches[1]);
}

function searchFor(string $locale, string $query): string
{
    return test()->get("/{$locale}/search?".http_build_query(['q' => $query]))->assertOk()->getContent();
}

beforeEach(function () {
    // These tests cover the site open for indexing (plan 15: closed by default).
    openSearchIndexing();
    $this->printer = Product::factory()->for(Brand::factory()->create(['name' => 'Godex']))->create([
        'sku' => 'GDX-G500',
        'name' => ['uz' => 'Oʻzbekcha yorliq printeri', 'ru' => 'Принтер этикеток Godex G500', 'en' => 'Godex G500 label printer'],
    ]);
    $this->scanner = Product::factory()->for(Brand::factory()->create(['name' => 'Zebra']))->create([
        'sku' => 'ZEB-DS2208',
        'name' => ['uz' => 'Shtrix-kod skaneri', 'ru' => 'Сканер штрихкода Zebra', 'en' => 'Zebra barcode scanner'],
    ]);
});

it('finds Uzbek names with any apostrophe variant', function (string $query) {
    expect(searchResultNames(searchFor('uz', $query)))->toBe(['Oʻzbekcha yorliq printeri']);
})->with(['oʻzbekcha', "o'zbekcha", 'oʼzbekcha', 'o‘zbekcha', 'ozbekcha', 'OʻZBEKCHA']);

it('finds Cyrillic names in any case and names in other locales', function (string $locale, string $query, string $expected) {
    expect(searchResultNames(searchFor($locale, $query)))->toBe([$expected]);
})->with([
    'ru lower' => ['ru', 'сканер', 'Сканер штрихкода Zebra'],
    'ru upper' => ['ru', 'СКАНЕР ШТРИХКОДА', 'Сканер штрихкода Zebra'],
    'ru name from en page' => ['en', 'сканер', 'Zebra barcode scanner'],
    'sku part' => ['en', 'zeb-ds', 'Zebra barcode scanner'],
    'brand' => ['ru', 'godex', 'Принтер этикеток Godex G500'],
    'every word must match' => ['en', 'zebra scanner', 'Zebra barcode scanner'],
]);

it('finds only published products', function () {
    Product::factory()->inactive()->create(['name' => ['uz' => 'Zebra draft', 'ru' => 'Zebra draft', 'en' => 'Zebra draft']]);
    Product::factory()->create(['name' => ['uz' => 'Zebra trashed', 'ru' => 'Zebra trashed', 'en' => 'Zebra trashed']])->delete();
    Product::factory()->inCategory(Category::factory()->inactive()->create())->create(['name' => ['uz' => 'Zebra hidden', 'ru' => 'Zebra hidden', 'en' => 'Zebra hidden']]);

    expect(searchResultNames(searchFor('en', 'zebra')))->toBe(['Zebra barcode scanner']);
});

it('treats LIKE wildcards literally', function (string $query) {
    expect(searchResultNames(searchFor('en', $query)))->toBe([]);
})->with(['%', '_', 'zeb%ds']);

it('keeps search pages out of the index and prompts for an empty query', function () {
    $html = $this->get('/ru/search')->assertOk()->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex,follow">')
        ->toContain(__('site.search.empty_query', [], 'ru'))
        ->not->toContain('data-product-card')
        ->and(searchFor('ru', 'nothing-like-this'))->toContain(e(__('site.search.nothing', ['query' => 'nothing-like-this'], 'ru')))
        ->and(searchFor('en', 'godex'))->toContain('<title>'.e(__('site.search.results_for', ['query' => 'godex'], 'en')).' — Poson</title>');
});

it('paginates 24 results per page', function () {
    Product::factory()->count(25)->create(['model' => 'PAGED']);

    $first = searchFor('en', 'paged');
    $second = $this->get('/en/search?q=paged&page=2')->assertOk()->getContent();

    expect(substr_count($first, 'data-product-card'))->toBe(24)
        ->and($first)->toContain('href="'.url('/en/search').'?q=paged&amp;page=2"')
        ->and(substr_count($second, 'data-product-card'))->toBe(1);
});

it('updates the results as the query changes', function () {
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);

    Livewire::test(Search::class)
        ->assertSee(__('site.search.empty_query', [], 'en'))
        ->set('q', 'godex')
        ->assertSee('Godex G500 label printer')
        ->assertDontSee('Zebra barcode scanner');
});
