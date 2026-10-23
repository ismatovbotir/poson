<?php

use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\CatalogMenu;
use Illuminate\Support\Facades\Blade;

/**
 * The header catalog dropdown panel of a page (between its marker comments).
 */
function catalogMenuPanel(string $html): string
{
    expect(substr_count($html, '<!-- catalog-menu:start -->'))->toBe(1)
        ->and(substr_count($html, '<!-- catalog-menu:end -->'))->toBe(1);

    $panel = (string) str($html)->between('<!-- catalog-menu:start -->', '<!-- catalog-menu:end -->');

    expect($panel)->toContain('id="site-catalog-menu"');

    return $panel;
}

/**
 * Link hrefs in a piece of HTML, in document order.
 *
 * @return list<string>
 */
function catalogMenuHrefs(string $html): array
{
    preg_match_all('/<a\s+href="([^"]+)"/', $html, $matches);

    return array_map(fn (string $href): string => html_entity_decode($href), $matches[1]);
}

it('renders "Show all" first, linking to the catalog index, with a no-JS fallback on the trigger', function (string $locale, string $label) {
    Category::factory()->create(['slug' => 'scanners']);

    $html = $this->get("/{$locale}")->assertOk()->getContent();
    $panel = catalogMenuPanel($html);

    expect(catalogMenuHrefs($panel)[0])->toBe(url("/{$locale}/catalog"))
        ->and($panel)->toContain($label)
        ->and(str($panel)->before(url("/{$locale}/catalog/scanners"))->toString())->toContain($label)
        ->and($html)->toContain('x-bind:aria-controls="\'site-catalog-menu\'"')
        ->and($html)->not->toMatch('/\saria-controls="site-catalog-menu"/')
        ->and($html)->toContain('x-bind:aria-expanded="open.toString()"')
        ->and($html)->toMatch('#<a\s+href="'.preg_quote(url("/{$locale}/catalog"), '#').'"\s+x-ref="trigger"#');
})->with([
    ['uz', 'Barchasini koʻrish'],
    ['ru', 'Показать все'],
    ['en', 'Show all'],
]);

it('lists the active top-level categories in sort order and hides inactive ones', function () {
    Category::factory()->create(['slug' => 'printers', 'sort' => 2, 'name' => ['uz' => 'Printerlar', 'ru' => 'Принтеры', 'en' => 'Printers']]);
    Category::factory()->create(['slug' => 'scanners', 'sort' => 1, 'name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);
    Category::factory()->inactive()->create(['slug' => 'hidden-root', 'sort' => 0]);
    $pos = Category::factory()->create(['slug' => 'pos', 'sort' => 3]);
    Category::factory()->child($pos)->inactive()->create(['slug' => 'hidden-child']);

    $panel = catalogMenuPanel($this->get('/ru')->assertOk()->getContent());

    expect(catalogMenuHrefs($panel))->toBe([
        url('/ru/catalog'),
        url('/ru/catalog/scanners'),
        url('/ru/catalog/printers'),
        url('/ru/catalog/pos'),
    ])
        ->and($panel)->toContain('Сканеры')
        ->and($panel)->not->toContain('hidden-root')
        ->and($panel)->not->toContain('hidden-child');
});

it('shows active subcategories under their parent, and children of a hidden parent nowhere', function () {
    $scanners = Category::factory()->create(['slug' => 'scanners', 'sort' => 1]);
    Category::factory()->child($scanners)->create(['slug' => 'handheld', 'sort' => 2, 'name' => ['uz' => 'Qoʻl skanerlari', 'ru' => 'Ручные сканеры', 'en' => 'Handheld scanners']]);
    Category::factory()->child($scanners)->create(['slug' => 'stationary', 'sort' => 1, 'name' => ['uz' => 'Statsionar skanerlar', 'ru' => 'Стационарные сканеры', 'en' => 'Stationary scanners']]);
    $hidden = Category::factory()->inactive()->create(['slug' => 'hidden-root', 'sort' => 2]);
    Category::factory()->child($hidden)->create(['slug' => 'orphan']);
    Category::factory()->create(['slug' => 'pos', 'sort' => 3]);

    $panel = catalogMenuPanel($this->get('/en')->assertOk()->getContent());

    expect(catalogMenuHrefs($panel))->toBe([
        url('/en/catalog'),
        url('/en/catalog/scanners'),
        url('/en/catalog/stationary'),
        url('/en/catalog/handheld'),
        url('/en/catalog/pos'),
    ])
        ->and($panel)->toContain('Handheld scanners')
        ->and($panel)->toContain('x-show="active === '.$scanners->id.'"');

    app()->setLocale('en');
    $items = CatalogMenu::items();

    expect(array_column($items, 'url'))->toBe([url('/en/catalog/scanners'), url('/en/catalog/pos')])
        ->and(array_column($items[0]['children'], 'label'))->toBe(['Stationary scanners', 'Handheld scanners'])
        ->and($items[1]['children'])->toBe([]);
});

it('escapes category names in link text and in the submenu aria-label', function () {
    $payload = '<img src=x onerror=alert(1)>';
    $root = Category::factory()->create(['slug' => 'xss-root', 'name' => ['uz' => 'Xavfsiz', 'ru' => $payload, 'en' => 'Safe']]);
    Category::factory()->child($root)->create(['slug' => 'xss-child', 'name' => ['uz' => 'Bola', 'ru' => $payload.' child', 'en' => 'Child']]);

    $panel = catalogMenuPanel($this->get('/ru')->assertOk()->getContent());
    $escaped = e($payload);

    expect($panel)->not->toContain($payload)
        ->and($panel)->toContain('<span class="flex-1">'.$escaped.'</span>')
        ->and($panel)->toContain('aria-label="'.$escaped.'"')
        ->and($panel)->toContain('>'.$escaped.' child</a>');
});

it('localizes category labels', function (string $locale, string $label) {
    Category::factory()->create(['slug' => 'scanners', 'name' => ['uz' => 'Skanerlar', 'ru' => 'Сканеры', 'en' => 'Scanners']]);

    expect(catalogMenuPanel($this->get("/{$locale}")->assertOk()->getContent()))->toContain($label);
})->with([
    ['uz', 'Skanerlar'],
    ['ru', 'Сканеры'],
    ['en', 'Scanners'],
]);

it('marks the current category and its top-level parent on a category page', function () {
    $scanners = Category::factory()->create(['slug' => 'scanners']);
    Category::factory()->child($scanners)->create(['slug' => 'handheld']);

    $panel = catalogMenuPanel($this->get('/en/catalog/handheld')->assertOk()->getContent());

    expect($panel)->toMatch('#href="'.preg_quote(url('/en/catalog/handheld'), '#').'"\s+aria-current="page"#')
        ->and($panel)->toMatch('#href="'.preg_quote(url('/en/catalog/scanners'), '#').'"[^>]*class="[^"]*\btext-brand-600"#')
        ->and(substr_count($panel, 'aria-current="page"'))->toBe(1);
});

it('renders from the cached category tree with bounded queries', function () {
    app()->setLocale('en');
    $roots = Category::factory()->count(3)->create();
    $roots->each(fn (Category $root) => Category::factory()->count(2)->child($root)->create());
    CategoryTree::flush();

    $cold = countQueries(fn () => Blade::render('<x-site.catalog-menu />'));
    $warm = countQueries(fn () => Blade::render('<x-site.catalog-menu />'));

    $more = Category::factory()->count(5)->create();
    $more->each(fn (Category $root) => Category::factory()->count(3)->child($root)->create());
    CategoryTree::flush();

    expect($cold)->toBeLessThanOrEqual(1)
        ->and($warm)->toBe(0)
        ->and(countQueries(fn () => Blade::render('<x-site.catalog-menu />')))->toBe($cold);
});
