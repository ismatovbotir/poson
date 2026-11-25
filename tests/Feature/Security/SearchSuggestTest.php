<?php

use App\Actions\Site\SuggestSearch;
use App\Enums\StockStatus;
use App\Livewire\Site\Search;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Plan 43 batch 2 security conditions for the header search suggestions (#40,
 * UQ2-1 … UQ2-8) and the search page chips / sort (#39, UQ2-9).
 */
function ssgUrl(string $query, string $locale = 'en'): string
{
    return "/{$locale}/search/suggest?".http_build_query(['q' => $query]);
}

/**
 * The pager link URLs of a results page (decoded).
 *
 * @return list<string>
 */
function ssgPagerLinks(string $html): array
{
    preg_match_all('#href="([^"]*[?&](?:amp;)?page=\d+[^"]*)"#', $html, $matches);

    return array_map(fn (string $url): string => html_entity_decode($url), $matches[1]);
}

/**
 * Card names on a search results page, in order.
 *
 * @return list<string>
 */
function ssgNames(string $html): array
{
    preg_match_all('#<h3[^>]*>\s*<a href="[^"]+/product/[^"]+"[^>]*>([^<]+)</a>#', $html, $matches);

    return array_map(fn (string $name): string => html_entity_decode(trim($name)), $matches[1]);
}

beforeEach(function () {
    openSearchIndexing();
});

describe('suggest route (UQ2-1, UQ2-6)', function () {
    it('is a GET route under the locale prefix with the search-suggest throttle', function () {
        $route = app('router')->getRoutes()->getByName('site.search.suggest');

        expect($route->uri())->toBe('{locale}/search/suggest')
            ->and($route->methods())->toBe(['GET', 'HEAD'])
            ->and($route->gatherMiddleware())->toContain('throttle:search-suggest');

        $this->get('/xx/search/suggest?q=zebra')->assertNotFound();
    });

    it('answers noindex and privately cacheable JSON', function () {
        $this->get(ssgUrl('zebra'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=60, private')
            ->assertExactJson(['products' => [], 'categories' => []]);
    });

    it('returns a quiet, empty 429 after 60 requests a minute per IP', function () {
        for ($i = 0; $i < 60; $i++) {
            $this->get(ssgUrl('ab'.$i))->assertOk();
        }

        $response = $this->get(ssgUrl('zebra'))->assertStatus(429)->assertHeader('X-Robots-Tag', 'noindex');

        expect($response->getContent())->toBe('[]');
        expect($response->headers->get('Retry-After'))->not->toBeNull();

        // Another IP has its own budget.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get(ssgUrl('zebra'))->assertOk();
    });
});

describe('suggest payload (UQ2-2 … UQ2-5)', function () {
    it('maps products to name, brand, category, url and image only: no price levels, stock or cost', function () {
        $category = Category::factory()->create(['slug' => 'scanners', 'name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
        Product::factory()->inCategory($category)->for(Brand::factory()->create(['name' => 'Zebra']))->create([
            'slug' => 'zebra-ds2208',
            'name' => ['uz' => 'Zebra DS2208', 'ru' => 'Zebra DS2208', 'en' => 'Zebra DS2208'],
            'price' => 123_456_700,
            'price_dealer' => 111_111_100,
            'price_wholesale' => 99_999_900,
            'stock_status' => StockStatus::InStock,
        ]);

        $response = $this->get(ssgUrl('zebra'))->assertOk();
        $products = $response->json('products');

        expect($products)->toHaveCount(1)
            ->and(array_keys($products[0]))->toBe(['name', 'brand', 'category', 'url', 'image'])
            ->and($products[0])->toMatchArray([
                'name' => 'Zebra DS2208',
                'brand' => 'Zebra',
                'category' => 'Scanners',
                'url' => url('/en/product/zebra-ds2208'),
                'image' => null,
            ]);

        expect($response->getContent())
            ->not->toContain('price')
            ->not->toContain('1111111')
            ->not->toContain('999999')
            ->not->toContain('1234567')
            ->not->toContain('stock')
            ->not->toContain('in_stock')
            ->not->toContain('cost')
            ->not->toContain('sku')
            ->not->toContain('search_text');
    });

    it('keeps an HTML product name as data: escaped in the JSON, never markup', function () {
        $name = '<img src=x onerror=alert(1)> Zebra';
        Product::factory()->create(['name' => ['uz' => $name, 'ru' => $name, 'en' => $name]]);

        $response = $this->get(ssgUrl('zebra'))->assertOk();

        expect($response->json('products.0.name'))->toBe($name)
            ->and($response->getContent())->not->toContain('<img')
            ->toContain(trim(json_encode('<img', JSON_HEX_TAG), '"'));
    });

    it('renders the suggestions with x-text only: no x-html, innerHTML or raw output', function () {
        $header = file_get_contents(resource_path('views/components/site/header.blade.php'));
        $js = file_get_contents(resource_path('js/app.js'));

        expect($header)->not->toContain('x-html')
            ->not->toContain('{!!')
            ->toContain('x-text="part.text"')
            ->and($js)->not->toContain('innerHTML')
            ->not->toContain('outerHTML')
            ->not->toContain('insertAdjacentHTML')
            ->not->toContain('x-html')
            ->toContain('AbortController')
            ->toContain('SUGGEST_DEBOUNCE_MS = 300')
            ->not->toMatch('#fetch\(\s*[\'"]https?://#');
    });

    it('excludes inactive, trashed and hidden-ancestor products and hidden categories', function () {
        $hiddenRoot = Category::factory()->inactive()->create(['name' => ['uz' => 'Zebra root', 'ru' => 'Zebra root', 'en' => 'Zebra root']]);
        $underHidden = Category::factory()->child($hiddenRoot)->create(['name' => ['uz' => 'Zebra child', 'ru' => 'Zebra child', 'en' => 'Zebra child']]);
        $visible = Category::factory()->create(['slug' => 'zebra-printers', 'name' => ['uz' => 'Zebra printerlari', 'ru' => 'Принтеры Zebra', 'en' => 'Zebra printers']]);

        Product::factory()->inCategory($visible)->inactive()->create(['name' => ['uz' => 'Zebra draft', 'ru' => 'Zebra draft', 'en' => 'Zebra draft']]);
        Product::factory()->inCategory($visible)->create(['name' => ['uz' => 'Zebra trashed', 'ru' => 'Zebra trashed', 'en' => 'Zebra trashed']])->delete();
        Product::factory()->inCategory($underHidden)->create(['name' => ['uz' => 'Zebra orphan', 'ru' => 'Zebra orphan', 'en' => 'Zebra orphan']]);
        Product::factory()->inCategory($visible)->create(['name' => ['uz' => 'Zebra ZD421', 'ru' => 'Zebra ZD421', 'en' => 'Zebra ZD421']]);

        $response = $this->get(ssgUrl('zebra'))->assertOk();

        expect(array_column($response->json('products'), 'name'))->toBe(['Zebra ZD421'])
            ->and($response->json('categories'))->toBe([['name' => 'Zebra printers', 'url' => url('/en/catalog/zebra-printers')]]);
    });

    it('matches categories in the current locale only, at most 5, and products at most 5', function () {
        foreach (range(1, 7) as $i) {
            Category::factory()->create(['name' => ['uz' => "Printer {$i}", 'ru' => "Принтер {$i}", 'en' => "Printer {$i}"]]);
            Product::factory()->create(['name' => ['uz' => "Printer model {$i}", 'ru' => "Printer model {$i}", 'en' => "Printer model {$i}"]]);
        }

        $en = $this->get(ssgUrl('printer'))->assertOk();
        $ru = $this->get(ssgUrl('принтер', 'ru'))->assertOk();

        expect($en->json('categories'))->toHaveCount(SuggestSearch::MAX_CATEGORIES)
            ->and($en->json('products'))->toHaveCount(SuggestSearch::MAX_PRODUCTS)
            ->and($ru->json('categories.0.name'))->toBe('Принтер 1')
            ->and($ru->json('categories.0.url'))->toStartWith(url('/ru/catalog/'))
            // "printer" is not in the ru category names.
            ->and($this->get(ssgUrl('printer', 'ru'))->json('categories'))->toBe([]);
    });

    it('runs no query under 2 characters after normalisation', function (mixed $query) {
        Product::factory()->create(['name' => ['uz' => 'Zebra', 'ru' => 'Zebra', 'en' => 'Zebra']]);

        $queries = [];
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            if (str_contains($event->sql, 'products') || str_contains($event->sql, 'categories')) {
                $queries[] = $event->sql;
            }
        });

        $response = $this->get('/en/search/suggest?'.http_build_query(['q' => $query]))->assertOk();

        expect($response->json())->toBe(['products' => [], 'categories' => []])
            ->and($queries)->toBe([]);
    })->with([
        'one letter' => ['z'],
        'spaces' => ['   '],
        'letter and apostrophes' => ["oʻ'"],
        'array' => [['zebra']],
        'empty' => [''],
    ]);

    it('caches by locale and a term hash, for at most 5 minutes', function () {
        expect(SuggestSearch::CACHE_SECONDS)->toBeLessThanOrEqual(300);

        Product::factory()->create(['name' => ['uz' => 'Zebra', 'ru' => 'Zebra', 'en' => 'Zebra']]);
        $this->get(ssgUrl('zebra'))->assertOk();

        expect(cache()->has('suggest:en:'.sha1('zebra')))->toBeTrue()
            ->and(cache()->has('suggest:ru:'.sha1('zebra')))->toBeFalse();
    });
});

describe('Mini App (UQ2-8)', function () {
    it('has no header, Alpine suggest code or suggest URL', function () {
        tmaEnabled();
        tmaSignIn(tmaUser());

        $html = $this->get('/tma/uz')->assertOk()->getContent();

        expect($html)->not->toContain('search/suggest')
            ->not->toContain('searchSuggest')
            ->not->toContain('data-suggest-url')
            ->not->toContain('siteHeader')
            ->and(file_get_contents(resource_path('js/tma.js')))->not->toContain('suggest')
            ->and(file_get_contents(resource_path('views/layouts/tma.blade.php')))->not->toContain('suggest');
    });

    it('links the site header to the same-origin suggest URL', function () {
        $this->get('/uz')->assertOk()
            ->assertSee('data-suggest-url="'.url('/uz/search/suggest').'"', false)
            ->assertSee('x-data="searchSuggest"', false);
    });
});

describe('search page chips and sort (UQ2-9)', function () {
    beforeEach(function () {
        config(['site.per_page' => 1]);
        $this->scanners = Category::factory()->create(['name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
        $this->printers = Category::factory()->create(['name' => ['uz' => 'Printerlar', 'ru' => 'Принтеры', 'en' => 'Printers']]);
        $this->hidden = Category::factory()->inactive()->create();
        Product::factory()->inCategory($this->scanners)->create(['price' => 300_000_00, 'name' => ['uz' => 'Zebra A', 'ru' => 'Zebra A', 'en' => 'Zebra A']]);
        Product::factory()->inCategory($this->scanners)->create(['price' => 100_000_00, 'name' => ['uz' => 'Zebra B', 'ru' => 'Zebra B', 'en' => 'Zebra B']]);
        Product::factory()->inCategory($this->printers)->create(['price' => 200_000_00, 'name' => ['uz' => 'Zebra C', 'ru' => 'Zebra C', 'en' => 'Zebra C']]);
        Product::factory()->inCategory($this->hidden)->create(['name' => ['uz' => 'Zebra H', 'ru' => 'Zebra H', 'en' => 'Zebra H']]);
        // A visible category with no matching product.
        $this->other = Category::factory()->create();
        Product::factory()->inCategory($this->other)->create(['name' => ['uz' => 'Honeywell', 'ru' => 'Honeywell', 'en' => 'Honeywell']]);
    });

    it('applies a chip of the current results and keeps it in the pager', function () {
        $html = $this->get('/en/search?q=zebra&category='.$this->printers->id)->assertOk()->getContent();

        expect(ssgNames($html))->toBe(['Zebra C'])
            ->and($html)->toContain('data-total="1"');

        $html = $this->get('/en/search?q=zebra&category='.$this->scanners->id)->assertOk()->getContent();

        expect($html)->toContain('data-total="2"')
            ->and(ssgPagerLinks($html))->not->toBeEmpty()
            ->each->toContain('category='.$this->scanners->id);
    });

    it('ignores a tampered chip parameter', function (Closure $value) {
        $value = $value->call($this);
        $html = $this->get('/en/search?'.http_build_query(['q' => 'zebra', 'category' => $value]))->assertOk()->getContent();

        expect($html)->toContain('data-total="3"')
            ->not->toContain('Zebra H')
            ->not->toContain('data-search-chip="'.$this->hidden->id.'"')
            ->not->toContain('data-search-chip="'.$this->other->id.'"')
            ->and(ssgPagerLinks($html))->not->toBeEmpty()
            ->each->not->toContain('category=');
    })->with([
        'hidden category' => [fn () => $this->hidden->id],
        'visible category without results' => [fn () => $this->other->id],
        'unknown id' => [fn () => 999999],
        'text' => [fn () => 'printers'],
        'negative' => [fn () => '-1'],
        'array' => [fn () => [$this->printers->id]],
        'sql' => [fn () => '1 or 1=1'],
    ]);

    it('shows at most 12 chips from one grouped query', function () {
        expect(Search::MAX_CHIPS)->toBe(12);

        $queries = 0;
        DB::listen(function (QueryExecuted $event) use (&$queries): void {
            if (str_contains($event->sql, 'group by') && str_contains($event->sql, 'category_id')) {
                $queries++;
            }
        });

        $html = $this->get('/en/search?q=zebra')->assertOk()->getContent();

        expect($queries)->toBe(1)
            ->and(substr_count($html, 'data-search-chip="'))->toBe(2);
    });

    it('sorts by a whitelisted sort and falls back to the default otherwise', function () {
        config(['site.per_page' => 24]);

        expect(ssgNames($this->get('/en/search?q=zebra&sort=price_asc')->assertOk()->getContent()))->toBe(['Zebra B', 'Zebra C', 'Zebra A'])
            ->and(ssgNames($this->get('/en/search?q=zebra&sort=price_desc')->assertOk()->getContent()))->toBe(['Zebra A', 'Zebra C', 'Zebra B']);

        $default = ssgNames($this->get('/en/search?q=zebra')->assertOk()->getContent());

        foreach (['price_dealer', 'id desc', 'price_wholesale_asc', ''] as $sort) {
            expect(ssgNames($this->get('/en/search?'.http_build_query(['q' => 'zebra', 'sort' => $sort]))->assertOk()->getContent()))->toBe($default);
        }

        expect(ssgNames($this->get('/en/search?q=zebra&sort[]=price_asc')->assertOk()->getContent()))->toBe($default);
    });

    it('keeps only normalised values in the pager links', function () {
        $html = $this->get('/en/search?'.http_build_query(['q' => 'zebra', 'sort' => 'price_asc', 'evil' => '<x>']))->assertOk()->getContent();
        $bad = $this->get('/en/search?'.http_build_query(['q' => 'zebra', 'sort' => 'bogus']))->assertOk()->getContent();

        expect(ssgPagerLinks($html))->not->toBeEmpty()
            ->each(fn ($link) => $link->toContain('sort=price_asc')->toContain('q=zebra')->not->toContain('evil'))
            ->and(ssgPagerLinks($bad))->not->toBeEmpty()
            ->each->not->toContain('sort=');
    });
});
