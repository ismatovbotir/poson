<?php

use App\Data\Site\CategoryTile;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use App\Rules\BrandSafeText;
use App\Support\CompanySettings;
use App\Support\Vat;

/**
 * Plan 43 UI/UX quick wins (#6, #8, #11, #25, #43, #44, #46, #47, #79, #80)
 * and their security conditions UQ-1 … UQ-8.
 */
function uqSetting(string $key, mixed $value): void
{
    Setting::factory()->setting($key, $value)->create();
}

/**
 * A published product of the given factory state in a visible category.
 */
function uqProduct(string $state = 'hardware', array $attributes = []): Product
{
    $factory = Product::factory()->inCategory(Category::factory()->create());
    $factory = $state === 'hardware' ? $factory : $factory->{$state}();

    return $factory->create(['slug' => 'uq-'.$state, ...$attributes]);
}

/**
 * The buy box markup of a product page.
 */
function uqBuyBox(string $html): string
{
    $start = strpos($html, 'data-buybox');
    $end = strpos($html, 'data-key-specs', $start) ?: strpos($html, 'role="tablist"', $start);

    return substr($html, $start, $end - $start);
}

describe('footer legal line (#11, UQ-1, UQ-3, UQ-6)', function () {
    it('shows the legal name and INN escaped, linking to the active contacts page', function (string $locale) {
        Page::factory()->system('contacts')->create();
        uqSetting('legal', [
            'legal_name' => ['uz' => '<script>x</script>', 'ru' => '<script>x</script>', 'en' => '<script>x</script>'],
            'inn' => '312573855',
            'bank' => 'Secret Bank',
            'mfo' => '00873',
            'account' => '20208000900123456001',
            'director' => 'Director Name',
            'legal_address' => 'Legal street 1',
        ]);

        $html = $this->get("/{$locale}")->assertOk()->getContent();
        $footer = substr($html, strpos($html, '<footer'));

        expect($footer)
            ->toContain('href="'.url("/{$locale}/page/contacts").'" class="transition-colors hover:text-white focus-visible:outline-white" data-footer-legal>&lt;script&gt;x&lt;/script&gt; · '.e(__('site.footer.inn', ['inn' => '312573855'], $locale)).'</a>')
            ->not->toContain('<script>x</script>')
            ->not->toContain('Secret Bank')
            ->not->toContain('00873')
            ->not->toContain('20208000900123456001')
            ->not->toContain('Director Name')
            ->not->toContain('Legal street 1');
    })->with(['uz', 'ru', 'en']);

    it('is plain text while the contacts page is inactive', function () {
        Page::factory()->system('contacts')->inactive()->create();
        uqSetting('legal', ['legal_name' => ['uz' => 'POSON MChJ', 'ru' => 'ООО «POSON»', 'en' => 'POSON LLC'], 'inn' => '312573855']);

        $html = $this->get('/en')->assertOk()->getContent();

        expect($html)->toContain('<span data-footer-legal>POSON LLC · INN 312573855</span>')
            ->not->toContain('/en/page/contacts');
    });

    it('shows only the filled-in parts and nothing when both are empty', function () {
        uqSetting('legal', ['legal_name' => ['uz' => '', 'ru' => '', 'en' => ''], 'inn' => '312573855']);

        expect($this->get('/ru')->assertOk()->getContent())->toContain('data-footer-legal>ИНН 312573855<');

        Setting::query()->where('key', 'legal')->update(['value' => json_encode(['legal_name' => ['uz' => '', 'ru' => '', 'en' => ''], 'inn' => 'not-an-inn'])]);
        CompanySettings::flush();
        app()->forgetInstance(CompanySettings::class);

        expect($this->get('/ru')->assertOk()->getContent())->not->toContain('data-footer-legal');
    });
});

describe('mobile call button (#6, UQ-2)', function () {
    it('dials the primary phone in E.164 with a translated label', function (string $locale) {
        uqSetting('phones', [['label' => 'orders', 'number' => '+998901234567', 'primary' => true, 'site' => true]]);

        $html = $this->get("/{$locale}")->assertOk()->getContent();

        expect($html)->toMatch('#<a\s+href="tel:\+998901234567"\s+class="[^"]*size-11[^"]*lg:hidden"\s+aria-label="'.preg_quote(e(__('site.header.call', [], $locale)), '#').'"\s+data-header-call#');
    })->with(['uz', 'ru', 'en']);

    it('is not rendered without a valid phone', function () {
        uqSetting('phones', [['label' => 'orders', 'number' => 'call me', 'primary' => true, 'site' => true]]);

        expect($this->get('/uz')->assertOk()->getContent())->not->toContain('data-header-call');
    });

    it('has a label in every locale', function () {
        foreach (['uz', 'ru', 'en'] as $locale) {
            expect(trans('site.header.call', [], $locale))->not->toBe('site.header.call')->not->toBe('');
        }
    });
});

describe('buy box trust lines by type (#43, #44, UQ-3, UQ-7)', function () {
    it('shows delivery, warranty and setup for hardware inside the buy box, the warranty linked to the active page', function (string $locale) {
        Page::factory()->system('warranty-service')->create();
        uqProduct('hardware', ['warranty_months' => 12]);

        $box = uqBuyBox($this->get("/{$locale}/product/uq-hardware")->assertOk()->getContent());

        expect($box)->toContain('data-trust-lines')
            ->toContain('data-trust="delivery"')
            ->toContain('data-trust="setup"')
            ->toContain('href="'.url("/{$locale}/page/warranty-service").'"')
            ->toContain(e(__('site.product.delivery.warranty', ['months' => trans_choice('site.product.months', 12, ['count' => 12], $locale)], $locale)))
            ->and(strpos($box, 'data-ctas'))->toBeLessThan(strpos($box, 'data-trust-lines'));
    })->with(['uz', 'ru', 'en']);

    it('does not link the warranty while the page is inactive', function () {
        Page::factory()->system('warranty-service')->inactive()->create();
        uqProduct();

        $box = uqBuyBox($this->get('/en/product/uq-hardware')->assertOk()->getContent());

        expect($box)->toContain('data-trust="warranty"')
            ->not->toContain('data-trust-link')
            ->not->toContain('warranty-service');
    });

    it('shows compatibility, stock and custom sizes for consumables', function () {
        uqProduct('consumable');

        $box = uqBuyBox($this->get('/ru/product/uq-consumable')->assertOk()->getContent());

        expect($box)->toContain(e(__('site.product.trust.compatibility', [], 'ru')))
            ->toContain(e(__('site.product.trust.stock', [], 'ru')))
            ->toContain(e(__('site.product.trust.custom_sizes', [], 'ru')))
            ->not->toContain(e(__('site.product.delivery.setup', [], 'ru')))
            ->not->toContain(e(__('site.product.delivery.warranty_on_request', [], 'ru')));
    });

    it('picks the lines by the product type', function (string $state, array $keys) {
        uqProduct($state);

        $box = uqBuyBox($this->get("/en/product/uq-{$state}")->assertOk()->getContent());
        preg_match_all('#data-trust="([a-z_]+)"#', $box, $matches);

        expect($matches[1])->toBe($keys);
    })->with([
        'hardware' => ['hardware', ['delivery', 'warranty', 'setup']],
        'consumable' => ['consumable', ['compatibility', 'stock', 'custom_sizes']],
        'software' => ['software', ['software_setup', 'support']],
        'service' => ['service', ['service', 'support']],
    ]);

    it('keeps the new copy brand-safe in every locale', function () {
        foreach (['uz', 'ru', 'en'] as $locale) {
            foreach (['compatibility', 'stock', 'custom_sizes', 'software_setup', 'support'] as $key) {
                $text = trans("site.product.trust.{$key}", [], $locale);

                expect($text)->not->toBe("site.product.trust.{$key}")
                    ->and(BrandSafeText::violates($text))->toBeFalse();
            }

            foreach (['site.header.call', 'site.footer.inn'] as $key) {
                expect(trans($key, [], $locale))->not->toBe($key)
                    ->and(BrandSafeText::violates(trans($key, [], $locale)))->toBeFalse();
            }
        }
    });
});

describe('product page price, tabs and colours (#46, #47, #79, #80, UQ-4)', function () {
    it('notes VAT under the price in every locale, but not on price on request', function (string $locale) {
        uqProduct();
        uqProduct('hardware', ['slug' => 'uq-on-request', 'price' => null]);

        $note = e(__('checkout.summary.vat', ['percent' => Vat::PERCENT], $locale));
        $html = $this->get("/{$locale}/product/uq-hardware")->assertOk()->getContent();

        expect($html)->toContain('<p class="mt-1.5 text-[13px] text-ink-2" data-vat-note>'.$note.'</p>')
            ->and(strpos($html, 'data-price'))->toBeLessThan(strpos($html, 'data-vat-note'))
            ->and($this->get("/{$locale}/product/uq-on-request")->assertOk()->getContent())->not->toContain('data-vat-note');
    })->with(['uz', 'ru', 'en']);

    it('renders an ARIA tablist with the keyboard component and no inline script', function () {
        uqProduct();

        $html = $this->get('/en/product/uq-hardware')->assertOk()->getContent();

        expect($html)->toContain('x-data="siteTabs"')
            ->toContain('role="tablist" x-on:keydown="key($event)"')
            ->toMatch('#id="tab-description"\s+role="tab"\s+aria-controls="panel-description"\s+aria-selected="true"\s+tabindex="0"\s+data-tab="description"#')
            ->toMatch('#id="tab-specs"\s+role="tab"\s+aria-controls="panel-specs"\s+aria-selected="false"\s+tabindex="-1"\s+data-tab="specs"#')
            ->toContain('id="panel-specs" role="tabpanel" aria-labelledby="tab-specs"')
            ->not->toContain('siteTabs(')
            ->and(file_get_contents(resource_path('js/app.js')))->toContain("window.Alpine.data('siteTabs'");
    });

    it('uses the ink stock text with a coloured dot and the muted text token', function () {
        uqProduct();

        $html = $this->get('/en/product/uq-hardware')->assertOk()->getContent();

        expect($html)->toContain('class="inline-flex items-center gap-1.5 text-[13px] font-medium text-ink" data-stock="in_stock"')
            ->toContain('<span class="size-[7px] rounded-full bg-ok" aria-hidden="true"></span>')
            ->toContain('text-ink-muted')
            ->and(file_get_contents(resource_path('css/app.css')))->toContain('--color-ink-muted: #64748b;');
    });
});

describe('cards and tiles (#8, #25, UQ-8)', function () {
    it('hides a zero count on a category tile without any query', function () {
        $queries = countQueries(function () use (&$empty, &$filled, &$unknown): void {
            $empty = (string) $this->blade('<x-site.category-tile :tile="$tile" />', ['tile' => new CategoryTile('Services', '/en/catalog/services', 'wrench', 0)]);
            $filled = (string) $this->blade('<x-site.category-tile :tile="$tile" />', ['tile' => new CategoryTile('Scanners', '/en/catalog/scanners', 'scan', 3)]);
            $unknown = (string) $this->blade('<x-site.category-tile :tile="$tile" />', ['tile' => new CategoryTile('Printers', '/en/catalog/printers', 'scan')]);
        });

        expect($queries)->toBe(0)
            ->and($empty)->toContain('Services')->not->toContain('<small')
            ->and($filled)->toContain('<small class="text-sm text-ink-2">'.trans_choice('site.card.products_count', 3, ['count' => 3]).'</small>')
            ->and($unknown)->not->toContain('<small');
    });

    it('clamps the card title and spec line', function () {
        Product::factory()->featured()->create();

        $html = $this->get('/en')->assertOk()->getContent();

        expect($html)->toContain('<h3 class="line-clamp-3 text-sm');
    });
});
