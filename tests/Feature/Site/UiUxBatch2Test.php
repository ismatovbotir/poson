<?php

use App\Enums\StockStatus;
use App\Livewire\Site\Search;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\Setting;
use App\Support\Catalog\ProductCards;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Plan 43 UI/UX batch 2: #5 (mobile search row on scroll), #26 (units in
 * facets and cards), #27 (redundant facets), #39 (search chips and sort),
 * #41 (one search field on mobile), #42 (search empty state), #82 (focus
 * outlines) and C (telegram_status queue worker hints). Security conditions
 * UQ2-* are covered in tests/Feature/Security/SearchSuggestTest.php.
 */
function ub2Setting(string $key, mixed $value): void
{
    Setting::factory()->setting($key, $value)->create();
}

/**
 * A ribbons category with a "resolution" (dpi, two options), an "ink_side"
 * (one option every product has) and a "core" (mm) attribute, two products of one brand, in stock.
 *
 * @return array<string, mixed>
 */
function ub2Fixture(): array
{
    $category = Category::factory()->create(['slug' => 'ribbons', 'name' => ['uz' => 'Ribbonlar', 'ru' => 'Риббоны', 'en' => 'Ribbons']]);
    $resolution = Attribute::factory()->select(['203', '300'])->create(['code' => 'resolution', 'unit' => 'dpi', 'name' => ['uz' => 'Aniqlik', 'ru' => 'Разрешение', 'en' => 'Resolution']]);
    $inkSide = Attribute::factory()->select(['OUT', 'IN'])->create(['code' => 'ink_side', 'name' => ['uz' => 'Boʻyoq tomoni', 'ru' => 'Сторона краски', 'en' => 'Ink side']]);
    $core = Attribute::factory()->select(['25', '12.7 mm'])->create(['code' => 'core', 'unit' => 'mm', 'name' => ['uz' => 'Vtulka', 'ru' => 'Втулка', 'en' => 'Core diameter']]);

    foreach ([$resolution, $inkSide, $core] as $sort => $attribute) {
        $category->attributes()->attach($attribute->id, ['sort' => $sort, 'is_required' => false, 'is_filterable' => true]);
    }

    $option = fn (Attribute $attribute, string $value): AttributeOption => $attribute->options()->get()->first(fn (AttributeOption $option) => $option->getTranslation('value', 'uz') === $value);
    $brand = Brand::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);

    $a = Product::factory()->inCategory($category)->for($brand)->create(['stock_status' => StockStatus::InStock, 'name' => ['uz' => 'Ribbon A', 'ru' => 'Ribbon A', 'en' => 'Ribbon A']]);
    $b = Product::factory()->inCategory($category)->for($brand)->create(['stock_status' => StockStatus::InStock, 'name' => ['uz' => 'Ribbon B', 'ru' => 'Ribbon B', 'en' => 'Ribbon B']]);

    ProductAttributeValue::factory()->for($a)->option($option($resolution, '203'))->key()->create(['sort' => 1]);
    ProductAttributeValue::factory()->for($b)->option($option($resolution, '300'))->key()->create(['sort' => 1]);
    ProductAttributeValue::factory()->for($a)->option($option($inkSide, 'OUT'))->key()->create(['sort' => 2]);
    ProductAttributeValue::factory()->for($b)->option($option($inkSide, 'OUT'))->create();
    ProductAttributeValue::factory()->for($a)->option($option($core, '25'))->key()->create(['sort' => 3]);
    ProductAttributeValue::factory()->for($b)->option($option($core, '12.7 mm'))->create();

    return compact('category', 'resolution', 'inkSide', 'core', 'brand', 'a', 'b') + ['out' => $option($inkSide, 'OUT')];
}

/**
 * The markup of one filter fieldset (data-filter="code"), or '' when absent.
 */
function ub2Facet(string $html, string $code): string
{
    $start = strpos($html, 'data-filter="'.$code.'"');

    if ($start === false) {
        return '';
    }

    return substr($html, $start, strpos($html, '</fieldset>', $start) - $start);
}

beforeEach(function () {
    openSearchIndexing();
});

describe('mobile header search row (#5)', function () {
    it('keeps the first row sticky and hides only the search row on scroll, respecting reduced motion', function () {
        $html = $this->get('/uz')->assertOk()->getContent();
        $header = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));

        expect($header)
            ->toContain('class="sticky top-0 z-40')
            ->toContain('x-data="siteHeader"')
            ->toContain('x-on:scroll.window.passive="scrolled()"')
            ->toContain('data-header-search')
            ->toContain("searchHidden ? 'max-md:-translate-y-full max-md:opacity-0 max-md:invisible' : ''")
            ->toContain('motion-reduce:transition-none')
            // Batch 1: the 44px call button below 1100px stays.
            ->toContain('lg:hidden')
            ->and($html)->toContain('data-header-search-spacer');

        $js = file_get_contents(resource_path('js/app.js'));

        expect($js)->toContain("Alpine.data('siteHeader'")
            ->toContain("Alpine.data('siteTabs'")
            ->toContain('(min-width: 56.25rem)');
    });
});

describe('units in facets and cards (#26)', function () {
    it('prints the unit after numeric option labels in the facets, chips and card specs', function () {
        $fixture = ub2Fixture();
        $dpi = $fixture['resolution']->unitLabel();
        $mm = $fixture['core']->unitLabel();

        $html = $this->get('/en/catalog/ribbons')->assertOk()->getContent();

        expect(ub2Facet($html, 'resolution'))->toContain('<span>203 '.$dpi.'</span>')->toContain('<span>300 '.$dpi.'</span>')
            // A label that already ends with the unit isn't doubled.
            ->and(ub2Facet($html, 'core'))->toContain('<span>25 '.$mm.'</span>')->toContain('<span>12.7 mm</span>')->not->toContain('mm mm')
            ->and($html)->toContain('203 '.$dpi.' · OUT · 25 '.$mm);

        $filtered = $this->get('/en/catalog/ribbons?f[resolution][]='.$fixture['resolution']->options()->first()->id)->getContent();

        expect($filtered)->toContain('Resolution: 203 '.$dpi);
    });

    it('leaves text options and unit-less attributes alone and escapes the unit', function () {
        $attribute = Attribute::factory()->select(['USB'])->make(['unit' => '<b>u</b>']);

        expect($attribute->optionWithUnit('USB'))->toBe('USB')
            ->and($attribute->optionWithUnit('203'))->toBe('203 <b>u</b>')
            ->and(Attribute::factory()->select()->make(['unit' => null])->optionWithUnit('203'))->toBe('203');

        $fixture = ub2Fixture();
        $fixture['resolution']->update(['unit' => '<b>u</b>']);

        $html = $this->get('/en/catalog/ribbons')->assertOk()->getContent();

        expect($html)->toContain('203 &lt;b&gt;u&lt;/b&gt;')->not->toContain('203 <b>u</b>');
    });

    it('adds the localized unit to purely numeric labels only, in every locale', function (string $locale) {
        app()->setLocale($locale);
        $mm = Attribute::factory()->select()->make(['unit' => 'mm']);
        $m = Attribute::factory()->select()->make(['unit' => 'm']);
        $unit = __('catalog.units.mm', [], $locale);

        expect($mm->optionWithUnit('25'))->toBe('25 '.$unit)
            ->and($mm->optionWithUnit('12,7'))->toBe('12,7 '.$unit)
            ->and($mm->optionWithUnit('100 x 150'))->toBe('100 x 150 '.$unit)
            // Already with the unit: stored, localized in any locale, any case, with or without a space.
            ->and($mm->optionWithUnit('25 mm'))->toBe('25 mm')
            ->and($mm->optionWithUnit('25MM'))->toBe('25MM')
            ->and($mm->optionWithUnit('25 мм'))->toBe('25 мм')
            // Not numeric: unchanged.
            ->and($mm->optionWithUnit('USB 3.0'))->toBe('USB 3.0')
            ->and($mm->optionWithUnit('Wi-Fi 6'))->toBe('Wi-Fi 6')
            ->and($mm->optionWithUnit('OUT'))->toBe('OUT')
            // "3 dim" doesn't end with the unit "m", and isn't numeric either.
            ->and($m->optionWithUnit('3 dim'))->toBe('3 dim')
            ->and($m->optionWithUnit('3'))->toBe('3 '.$m->unitLabel());

        $fixture = ub2Fixture();
        $port = Attribute::factory()->select(['USB 3.0', 'Wi-Fi 6'])->create(['code' => 'port', 'unit' => 'mm']);
        ProductAttributeValue::factory()->for($fixture['a'])->option($port->options()->first())->key()->create(['sort' => 4]);

        $product = $fixture['a']->fresh(['keySpecValues.attribute', 'keySpecValues.option']);
        $dpi = $fixture['resolution']->unitLabel();

        expect(array_column(ProductCards::keySpecs($product), 'value'))
            ->toBe(['203 '.$dpi, 'OUT', '25 '.$unit, 'USB 3.0']);
    })->with(['uz', 'ru', 'en']);
});

describe('redundant facets (#27, UQ2-10)', function () {
    it('hides a single-option group every result has: attribute, brand and stock', function () {
        ub2Fixture();

        $html = $this->get('/en/catalog/ribbons')->assertOk()->getContent();

        expect(ub2Facet($html, 'ink_side'))->toBe('')
            ->and(ub2Facet($html, 'resolution'))->not->toBe('')
            ->and($html)->not->toContain('wire:key="filter-brand"')
            ->not->toContain('wire:key="filter-stock"');
    });

    it('keeps a hidden group visible while one of its values is ticked', function () {
        $fixture = ub2Fixture();

        $html = $this->get('/en/catalog/ribbons?f[ink_side][]='.$fixture['out']->id.'&brand[]=zebra&stock[]=in_stock')->assertOk()->getContent();

        expect(ub2Facet($html, 'ink_side'))->toContain('checked')
            ->and($html)->toContain('wire:key="filter-brand"')
            ->toContain('wire:key="filter-stock"')
            ->toContain('data-total="2"');
    });

    it('keeps a single option that narrows the results', function () {
        $fixture = ub2Fixture();
        // A third product without an ink side: OUT (2) no longer matches all 3.
        Product::factory()->inCategory($fixture['category'])->for($fixture['brand'])->create(['stock_status' => StockStatus::InStock]);

        $html = $this->get('/en/catalog/ribbons')->assertOk()->getContent();

        expect(ub2Facet($html, 'ink_side'))->toContain('<span>OUT</span>');
    });

    it('leaves filter validation unchanged', function () {
        $fixture = ub2Fixture();

        $this->get('/en/catalog/ribbons?f[ink_side][]=999999')->assertOk()->assertSee('data-total="2"', false);
        $this->get('/en/catalog/ribbons?f[ink_side][]='.$fixture['out']->id)->assertOk()->assertSee('data-total="2"', false);
    });
});

describe('search chips and sort (#39)', function () {
    it('shows category chips with counts, an All chip and the catalog sort', function () {
        $scanners = Category::factory()->create(['name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
        $printers = Category::factory()->create(['name' => ['uz' => 'Printerlar', 'ru' => 'Принтеры', 'en' => 'Printers']]);
        Product::factory()->count(2)->inCategory($scanners)->create(['name' => ['uz' => 'Zebra S', 'ru' => 'Zebra S', 'en' => 'Zebra S']]);
        Product::factory()->inCategory($printers)->create(['name' => ['uz' => 'Zebra P', 'ru' => 'Zebra P', 'en' => 'Zebra P']]);

        $html = $this->get('/en/search?q=zebra')->assertOk()->getContent();
        $chips = substr($html, strpos($html, 'data-search-chips'), strpos($html, '</ul>', strpos($html, 'data-search-chips')) - strpos($html, 'data-search-chips'));

        expect($chips)->toContain(__('site.search.all_categories', [], 'en'))
            ->toContain('data-search-chip="'.$scanners->id.'"')
            ->toContain('data-search-chip="'.$printers->id.'"')
            ->and(strpos($chips, 'Scanners'))->toBeLessThan(strpos($chips, 'Printers'))
            ->and($html)->toContain('id="search-sort"')
            ->toContain('value="price_asc"');
    });

    it('hides the chips when the results are in one category', function () {
        Product::factory()->count(2)->inCategory(Category::factory()->create())->create(['name' => ['uz' => 'Zebra', 'ru' => 'Zebra', 'en' => 'Zebra']]);

        expect($this->get('/en/search?q=zebra')->assertOk()->getContent())->not->toContain('data-search-chips')->toContain('id="search-sort"');
    });

    it('selects a chip through Livewire and resets it when the query changes', function () {
        $scanners = Category::factory()->create();
        $printers = Category::factory()->create();
        Product::factory()->inCategory($scanners)->create(['name' => ['uz' => 'Zebra S', 'ru' => 'Zebra S', 'en' => 'Zebra S']]);
        Product::factory()->inCategory($printers)->create(['name' => ['uz' => 'Zebra P', 'ru' => 'Zebra P', 'en' => 'Zebra P']]);

        URL::defaults(['locale' => 'en']);
        app()->setLocale('en');

        Livewire::withQueryParams(['q' => 'zebra'])
            ->test(Search::class)
            ->call('selectCategory', $printers->id)
            ->assertSet('category', $printers->id)
            ->assertSee('Zebra P')
            ->assertDontSee('Zebra S')
            ->call('selectCategory', 'nope')
            ->assertSet('category', null)
            ->call('selectCategory', $printers->id)
            ->set('q', 'zebra s')
            ->assertSet('category', null);
    });
});

describe('search page field and empty state (#41, #42, UQ2-11)', function () {
    it('hides the page search field below 900px', function () {
        expect($this->get('/en/search?q=zebra')->assertOk()->getContent())
            ->toMatch('#<form role="search"[^>]*class="relative mb-8 max-w-2xl max-md:hidden"[^>]*data-search-page-form#');
    });

    it('offers the phone, Telegram and up to 4 popular visible categories when nothing is found', function () {
        ub2Setting('phones', [['label' => 'orders', 'number' => '+998901234567', 'primary' => true, 'site' => true]]);
        ub2Setting('social', [['network' => 'telegram', 'url' => 'https://t.me/poson_uz']]);

        foreach (range(1, 5) as $i) {
            Product::factory()->count($i)->inCategory(Category::factory()->create(['slug' => "cat-{$i}", 'name' => ['uz' => "Cat {$i}", 'ru' => "Cat {$i}", 'en' => "Cat {$i}"]]))->create();
        }

        Product::factory()->count(9)->inCategory(Category::factory()->inactive()->create(['slug' => 'hidden-cat']))->create();

        $html = $this->get('/en/search?q=qwertyzzz')->assertOk()->getContent();
        $popular = substr($html, strpos($html, 'data-search-popular'), strpos($html, '</nav>', strpos($html, 'data-search-popular')) - strpos($html, 'data-search-popular'));

        expect($html)->toContain('href="tel:+998901234567"')
            ->toContain('href="https://t.me/poson_uz"')
            ->and(substr_count($popular, '/en/catalog/'))->toBe(4)
            ->and($popular)->toContain('/en/catalog/cat-5')
            ->not->toContain('/en/catalog/cat-1"')
            ->not->toContain('hidden-cat');
    });

    it('drops a Telegram link that is not https://t.me/ and shows no contacts for an empty query', function () {
        ub2Setting('social', [['network' => 'telegram', 'url' => 'https://telegram.me/poson_uz']]);
        ub2Setting('phones', []);

        expect($this->get('/en/search?q=qwertyzzz')->assertOk()->getContent())->not->toContain('data-search-telegram')
            ->and($this->get('/en/search')->assertOk()->getContent())->not->toContain('data-search-contacts')->not->toContain('data-search-popular');
    });
});

describe('focus outlines (#82)', function () {
    it('lets the global focus-visible outline show on the header search, filters, sort and roll quantity', function (string $view) {
        expect(file_get_contents(resource_path("views/{$view}")))->not->toContain('focus:outline-none');
    })->with([
        'components/site/header.blade.php',
        'livewire/site/catalog/index.blade.php',
        'livewire/site/catalog/roll-picker.blade.php',
        'livewire/site/search.blade.php',
    ]);
});

describe('queue worker hints (C, UQ2-12)', function () {
    it('points to the schedule:run cron line, not supervisor, in every locale', function (string $locale) {
        foreach (['next', 'next_never'] as $key) {
            $text = trans("telegram_status.rows.worker.{$key}", [], $locale);

            expect($text)->toContain('schedule:run')
                ->not->toContain('supervisor')
                ->not->toContain('queue:work')
                ->not->toContain('/path/')
                ->not->toContain('.env');
        }
    })->with(['uz', 'ru', 'en']);
});
