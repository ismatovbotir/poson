<?php

require_once __DIR__.'/Chat/WidgetHelpers.php';
require_once __DIR__.'/Cart/CartHelpers.php';

use App\Data\Site\ProductCta;
use App\Enums\LeadFlow;
use App\Enums\ProductCtaKind;
use App\Enums\Role;
use App\Events\LeadCreated;
use App\Livewire\Site\Cart\QuoteForm;
use App\Livewire\Site\Chat\Widget;
use App\Livewire\Site\LeadForm;
use App\Livewire\Site\LeadModal;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Cart\CartToken;
use App\Support\Catalog\ProductCtas;
use App\Support\Chat\WebChatDialog;
use App\Support\Chat\WebChatToken;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 42: the quote request modal (Site\LeadModal) and its triggers; security conditions QM-1–QM-12.
 */
beforeEach(function () {
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);
});

function qmModal(?string $pageUrl = 'http://localhost/en/catalog'): Testable
{
    return Livewire::test(LeadModal::class, ['pageUrl' => $pageUrl]);
}

/**
 * The modal's HTML without the per-render Livewire ids, snapshots and keys.
 */
function qmStableHtml(Testable $component): string
{
    return (string) preg_replace('/\s(wire:snapshot|wire:effects|wire:id|wire:key)="[^"]*"/', '', $component->html());
}

/**
 * Data of the first component with this name in a page's HTML (from its wire:snapshot).
 *
 * @return array<string, mixed>|null
 */
function qmSnapshotData(string $html, string $name): ?array
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $raw) {
        $snapshot = json_decode(html_entity_decode($raw, ENT_QUOTES), true);

        if (($snapshot['memo']['name'] ?? null) === $name) {
            return $snapshot['data'];
        }
    }

    return null;
}

/**
 * @return list<array<string, mixed>>
 */
function qmGoals(Testable $component): array
{
    return collect(data_get($component->effects, 'dispatches', []))->where('name', 'poson-goal')->pluck('params')->values()->all();
}

function qmTracking(): void
{
    Setting::factory()->setting('tracking', ['enabled' => true, 'ga4' => '', 'metrika' => '98765432'])->create();
    config(['site.analytics_force' => true]);
}

function qmFill(Testable $component, string $phone = '90 123 45 67'): Testable
{
    $component->set('name', 'Aziz')->set('phone', $phone);
    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component;
}

describe('page render (QM-12)', function () {
    it('renders only an empty hidden dialog on localized pages: no form fields before open()', function () {
        $html = $this->get('/en')->assertOk()->getContent();
        $modal = substr($html, (int) strpos($html, 'data-lead-modal-root'));

        expect($html)->toContain('data-lead-modal-root')
            ->and($modal)->toContain('role="dialog"')
            ->toContain('aria-modal="true"')
            ->toContain('aria-labelledby="lead-modal-title"')
            ->toContain('x-trap.inert.noscroll.noautofocus="open"')
            ->toContain('z-[65]')
            ->not->toContain('wire:model="phone"')
            ->not->toContain('<form')
            // The inline home form stays as the no-JS fallback.
            ->and(substr_count($html, 'wire:model="phone"'))->toBe(1);
    });

    it('is not on the checkout page', function () {
        config(['site.cart_enabled' => true]);
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();

        $html = $this->withCookie(CartToken::COOKIE, $cart->token)->get('/en/checkout')->assertOk()->getContent();

        expect($html)->not->toContain('data-lead-modal-root')
            ->not->toContain('site.lead-modal');
    });

    it('QM-6: takes pageUrl from the page request without the query string', function () {
        $product = Product::factory()->create(['slug' => 'zebra-ds2208']);

        $html = $this->get('/uz/product/zebra-ds2208?utm_source=a&yclid=1')->assertOk()->getContent();

        expect(qmSnapshotData($html, 'site.lead-modal')['pageUrl'] ?? null)->toBe(url('/uz/product/zebra-ds2208'))
            ->and(qmSnapshotData($html, 'site.lead-modal')['locale'] ?? null)->toBe('uz');
    });

    it('QM-6: stores exactly the pageUrl the modal passes in', function () {
        $pageUrl = url('/uz/product/x');

        qmFill(Livewire::test(LeadForm::class, ['pageUrl' => $pageUrl, 'idPrefix' => 'lead-modal', 'inModal' => true]))->call('submit')->assertSet('sent', true);

        expect(Lead::query()->sole()->page_url)->toBe($pageUrl);
    });
});

describe('open() (QM-1, QM-2)', function () {
    it('opens the callback form', function () {
        $html = qmModal()->call('open', 'callback')->assertSet('kind', 'callback')->assertSet('productId', null)->html();

        expect($html)->toContain(e(__('site.lead_modal.title_callback')))
            ->toContain('id="lead-modal-name"')
            ->toContain('id="lead-modal-phone"')
            ->not->toContain('data-lead-product');
    });

    it('opens the quote form for a published product, showing "Name (SKU)"', function () {
        $product = Product::factory()->create(['sku' => 'ZEB-DS2208', 'name' => ['uz' => 'Skaner', 'ru' => 'Сканер', 'en' => 'Scanner DS']]);

        $html = qmModal()->call('open', 'product', $product->id)->assertSet('kind', 'product')->assertSet('productId', $product->id)->html();

        expect($html)->toContain(e(__('site.lead_modal.title_product')))
            ->toContain('Scanner DS (ZEB-DS2208)')
            ->not->toContain('data-lead-modal-unavailable');
    });

    it('opens a service request for a service product', function () {
        $product = Product::factory()->service()->create();

        $html = qmModal()->call('open', 'product', (string) $product->id)->assertSet('isServiceRequest', true)->html();

        expect($html)->toContain(e(__('site.lead_modal.title_service')))
            ->toContain(e(__('site.product.service_request.button')));
    });

    it('gives the same output for a hidden and a missing product (callback form + notice)', function (Closure $hide) {
        $product = Product::factory()->create(['sku' => 'SECRET-1', 'name' => ['uz' => 'Maxfiy', 'en' => 'Secret prototype']]);
        $hide($product);

        $hidden = qmModal()->call('open', 'product', $product->id)->assertSet('kind', 'callback')->assertSet('productId', null)->assertSet('productUnavailable', true);
        $missing = qmModal()->call('open', 'product', 999_999)->assertSet('kind', 'callback')->assertSet('productUnavailable', true);

        expect(qmStableHtml($hidden))->toBe(qmStableHtml($missing))
            ->and($hidden->html())->toContain(e(__('site.lead_form.product_unavailable')))
            ->not->toContain('SECRET-1')
            ->not->toContain('Secret prototype');
    })->with([
        'inactive' => [fn (Product $product) => $product->update(['is_active' => false])],
        'soft-deleted' => [fn (Product $product) => $product->delete()],
        'inactive category' => [fn (Product $product) => Category::query()->whereKey($product->category_id)->update(['is_active' => false])],
    ]);

    it('falls back to the callback form for a tampered kind', function (mixed $kind) {
        qmModal()->call('open', $kind)->assertStatus(200)->assertSet('kind', 'callback')->assertSee('id="lead-modal-phone"', false);
    })->with(['evil' => ['evil'], 'array' => [['product']], 'int' => [5], 'null' => [null], 'upper' => ['PRODUCT']]);

    it('treats a malformed product id as missing', function (mixed $productId) {
        Product::factory()->create(['id' => 1000]);
        Product::factory()->create(['id' => 1]);

        qmModal()->call('open', 'product', $productId)->assertStatus(200)
            ->assertSet('kind', 'callback')->assertSet('productId', null)->assertSet('productUnavailable', true);
    })->with([
        'array' => [[1]],
        'float' => [1.0],
        'exponent' => ['1e3'],
        'leading space' => [' 1'],
        'negative' => ['-1'],
        'zero' => [0],
        'text' => ['abc'],
        'bool' => [true],
    ]);

    it('has no Livewire event listener: only Alpine calls open()', function () {
        $attributes = (new ReflectionMethod(LeadModal::class, 'open'))->getAttributes(Livewire\Attributes\On::class);

        expect($attributes)->toBe([]);
    });

    it('rejects tampering with locked properties', function (string $property, mixed $value) {
        qmModal()->set($property, $value);
    })->with([
        'pageUrl' => ['pageUrl', 'https://evil.example'],
        'locale' => ['locale', 'de'],
        'attribution' => ['attribution', ['channel' => 'ai']],
        'kind' => ['kind', 'product'],
        'productId' => ['productId', 1],
        'productUnavailable' => ['productUnavailable', true],
        'isServiceRequest' => ['isServiceRequest', true],
        'rollTypeId' => ['rollTypeId', 1],
        'rolls' => ['rolls', 5],
        'cartEmpty' => ['cartEmpty', true],
        'formKey' => ['formKey', 'fixed'],
    ])->throws(CannotUpdateLockedPropertyException::class);

    it('mounts a fresh form on every open and unmounts it on close', function () {
        $component = qmModal()->call('open', 'callback');
        $firstKey = $component->get('formKey');

        $component->call('close')->assertSet('kind', null);
        expect($component->html())->not->toContain('wire:model="phone"');

        $component->call('open', 'callback');
        expect($component->get('formKey'))->not->toBe($firstKey)->toHaveLength(16)
            ->and($component->html())->toContain('wire:model="phone"');
    });
});

describe('cart (QM-8)', function () {
    beforeEach(fn () => config(['site.cart_enabled' => true]));

    it('opens the cart quote form for a cart with quotable lines', function () {
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();

        $html = qmModal()->call('open', 'cart')->assertSet('kind', 'cart')->assertSet('cartEmpty', false)->html();

        expect($html)->toContain(e(__('site.lead_modal.title_cart')))
            ->toContain('id="lead-modal-cart-phone"')
            ->toContain('data-cart-quote-form');
    });

    it('says there is nothing to quote for an empty or missing cart', function (bool $withCookie) {
        if ($withCookie) {
            visitorCart();
        }

        $html = qmModal()->call('open', 'cart')->assertSet('kind', 'cart')->assertSet('cartEmpty', true)->html();

        expect($html)->toContain(e(__('checkout.cart.errors.nothing_to_quote')))->not->toContain('data-cart-quote-form');
    })->with(['no cookie' => [false], 'empty cart' => [true]]);

    it('opens the callback form while the cart is off; QuoteForm itself is a 404', function () {
        config(['site.cart_enabled' => false]);

        qmModal()->call('open', 'cart')->assertStatus(200)->assertSet('kind', 'callback')
            ->assertSee('id="lead-modal-phone"', false)->assertDontSee('data-cart-quote-form', false);

        Livewire::test(QuoteForm::class)->assertStatus(404);
        $this->get('/en/cart')->assertNotFound();
    });
});

describe('escaping (QM-9)', function () {
    it('escapes a product name in the heading and the form label', function () {
        $product = Product::factory()->create(['sku' => 'XSS-1', 'name' => ['uz' => '<img src=x onerror=alert(1)>', 'en' => '<img src=x onerror=alert(1)>']]);

        $html = qmModal()->call('open', 'product', $product->id)->html();

        expect($html)->not->toContain('<img src=x')
            ->toContain(e('<img src=x onerror=alert(1)> (XSS-1)'));
    });

    it('renders the trigger product id as an integer', function () {
        $product = Product::factory()->quoteOnly()->create();
        $cta = collect(ProductCtas::for($product))->firstWhere('action', ProductCta::ACTION_LEAD_FORM);

        $html = Blade::render('<x-site.product-cta :cta="$cta" />', ['cta' => $cta]);

        expect($html)->toContain('data-lead-modal="product" data-product-id="'.$product->id.'"')
            ->toContain('href="'.e($cta->url).'"');
    });
});

describe('page request context (QM-5)', function () {
    it('L1: reads the stored attribution only, never writing the session (bots, staff, untracked)', function (Closure $as) {
        $as();
        session()->forget('attribution');

        qmModal()->assertSet('attribution', []);

        expect(session('attribution'))->toBeNull();
    })->with([
        'untracked request' => [fn () => null],
        'staff' => [fn () => test()->actingAs(staffUser(Role::SalesManager))],
    ]);

    it('L1: a bot page render leaves the session attribution empty', function () {
        $html = $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/en/catalog')->assertOk()->getContent();

        expect(qmSnapshotData($html, 'site.lead-modal')['attribution'] ?? null)->toBe([[], ['s' => 'arr']])
            ->and(session('attribution'))->toBeNull();
    });

    it('L1: a staff page render leaves the session attribution empty', function () {
        $this->actingAs(staffUser(Role::SalesManager))->get('/en/catalog')->assertOk();

        expect(session('attribution'))->toBeNull();
    });

    it('captures locale and attribution on the page request and passes them to the form', function () {
        app()->setLocale('ru');
        session()->put('attribution', ['channel' => 'search', 'source' => 'google', 'landing' => '/ru/catalog/scanners', 'utm_source' => 'google']);

        $modal = qmModal(url('/ru/catalog/scanners'))->assertSet('locale', 'ru')->assertSet('attribution.landing', '/ru/catalog/scanners');

        // The update request: no session attribution and another app locale.
        session()->forget('attribution');
        app()->setLocale('en');

        $html = $modal->call('open', 'callback')->html();
        $child = qmSnapshotData($html, 'site.lead-form');

        expect($child['locale'])->toBe('ru')
            ->and($child['pageUrl'])->toBe(url('/ru/catalog/scanners'))
            ->and(session('attribution'))->toBeNull();
    });

    it('stores the lead with the passed locale and landing, writing nothing to the session', function () {
        session()->forget('attribution');

        qmFill(Livewire::test(LeadForm::class, [
            'pageUrl' => url('/ru/catalog'),
            'locale' => 'ru',
            'attribution' => ['channel' => 'search', 'source' => 'google', 'landing' => '/ru/catalog', 'utm_source' => 'google'],
            'idPrefix' => 'lead-modal',
            'inModal' => true,
        ]))->call('submit')->assertSet('sent', true);

        $lead = Lead::query()->sole();

        expect($lead->locale)->toBe('ru')
            ->and($lead->landing_path)->toBe('/ru/catalog')
            ->and($lead->landing_path)->not->toContain('livewire')
            ->and($lead->page_url)->toBe(url('/ru/catalog'))
            ->and(session('attribution'))->toBeNull();
    });

    it('shows validation messages in the page locale', function () {
        app()->setLocale('ru');

        Livewire::test(LeadForm::class, ['locale' => 'ru', 'attribution' => [], 'pageUrl' => url('/ru'), 'inModal' => true])
            ->call('submit')
            ->assertSee('Поле имя обязательно.');
    });

    it('ignores an unsupported locale passed in', function () {
        Livewire::test(LeadForm::class, ['locale' => 'de', 'attribution' => []])->assertSet('locale', 'en');
    });
});

describe('goals and success view (QM-11)', function () {
    beforeEach(fn () => qmTracking());

    it('never sends a goal on open or close', function () {
        $product = Product::factory()->create();
        $component = qmModal()->call('open', 'product', $product->id)->call('close')->call('open', 'callback');

        expect(qmGoals($component))->toBe([]);
    });

    it('sends exactly one goal from the modal form per success, and no lead reference', function (Closure $make, string $goal) {
        $component = qmFill($make())->call('submit')->assertSet('sent', true);
        $first = qmGoals($component);
        $component->set('name', 'Aziz')->set('phone', '90 123 45 67')->call('submit');

        expect($first)->toBe([['goal' => $goal]])
            ->and(qmGoals($component))->toBe([])
            ->and(RateLimiter::attempts('site-lead:'.sha1('127.0.0.1')))->toBe(1)
            ->and(Lead::query()->count())->toBe(1)
            ->and($component->html())->not->toMatch('/L-\d+/')
            ->toContain('data-lead-modal-done');
    })->with([
        'callback' => [fn () => Livewire::test(LeadForm::class, ['inModal' => true, 'idPrefix' => 'lead-modal', 'attribution' => []]), 'lead_callback'],
        'product' => [fn () => Livewire::test(LeadForm::class, ['productId' => Product::factory()->create()->id, 'inModal' => true, 'attribution' => []]), 'lead_product_quote'],
        'service' => [fn () => Livewire::test(LeadForm::class, ['productId' => Product::factory()->service()->create()->id, 'inModal' => true, 'attribution' => []]), 'lead_product_service'],
    ]);

    it('sends no goal for a bot or a failed validation', function () {
        $bot = qmFill(Livewire::test(LeadForm::class, ['inModal' => true]))->set('fax_ext', 'spam')->call('submit')->assertSet('sent', true);
        $invalid = Livewire::test(LeadForm::class, ['inModal' => true])->call('submit')->assertHasErrors('name');

        expect(qmGoals($bot))->toBe([])->and(qmGoals($invalid))->toBe([])->and(Lead::query()->count())->toBe(0);
    });
});

describe('shared budgets (QM-10)', function () {
    it('counts inline, modal, cart and chat leads against one IP budget: the sixth in the modal is refused', function () {
        wcSetup();
        Event::fake([LeadCreated::class]);
        config(['site.cart_enabled' => true]);
        $ipKey = 'site-lead:'.sha1((string) request()->ip());

        // 1. the chat lead card.
        [, $token] = wcChat(['dialog' => WebChatDialog::withForm(LeadFlow::Callback)]);
        $this->travel(5)->seconds();
        app(WebChatToken::class)->issue($token);
        Cookie::flushQueuedCookies();
        Livewire::test(Widget::class, ['page' => '/en'])->set('name', 'Ali')->set('phone', '90 100 00 01')->call('submitLead')->assertHasNoErrors();

        // 2–3. inline forms.
        qmFill(Livewire::test(LeadForm::class), '90 100 00 02')->call('submit')->assertSet('sent', true);
        qmFill(Livewire::test(LeadForm::class), '90 100 00 03')->call('submit')->assertSet('sent', true);

        // 4. the cart quote.
        $cart = visitorCart();
        CartItem::factory()->for($cart)->create();
        qmFill(Livewire::test(QuoteForm::class), '90 100 00 04')->call('requestQuote')->assertSet('sent', true);

        // 5. the modal.
        qmFill(Livewire::test(LeadForm::class, ['inModal' => true, 'idPrefix' => 'lead-modal', 'attribution' => []]), '90 100 00 05')->call('submit')->assertSet('sent', true);

        expect(RateLimiter::attempts($ipKey))->toBe(LeadForm::RATE_LIMIT);

        // 6. the modal again: refused.
        qmFill(Livewire::test(LeadForm::class, ['inModal' => true, 'idPrefix' => 'lead-modal', 'attribution' => []]), '90 100 00 06')->call('submit')
            ->assertHasErrors('form')->assertSet('sent', false)
            ->assertSee(explode(':seconds', __('site.lead_form.error_rate_limited'))[0]);

        expect(Lead::query()->count())->toBe(5);
    });

    it('closes the chat panel when the modal opens, without focusing the launcher', function () {
        $launcher = file_get_contents(resource_path('views/components/site/chat/launcher.blade.php'));
        $js = file_get_contents(resource_path('js/lead-modal.js'));

        expect($launcher)->toContain('x-on:lead-modal-opened.window="open = false"')
            ->and($js)->toContain("new CustomEvent('lead-modal-opened')");
    });
});

describe('triggers (plan 42 §4, §5)', function () {
    it('marks the header, mobile menu and home CTAs as callback triggers, hrefs unchanged', function () {
        $html = $this->get('/en')->assertOk()->getContent();

        expect(substr_count($html, 'data-lead-modal="callback"'))->toBeGreaterThanOrEqual(4)
            ->and(preg_match_all('/href="#lead"\s+data-lead-modal="callback"/', $html))->toBe(2);
    });

    it('marks lead-form CTAs on the product page; href stays #quote, the service link stays a link', function () {
        $product = Product::factory()->quoteOnly()->create(['slug' => 'zebra-zt411', 'price' => null]);

        $html = $this->get('/en/product/zebra-zt411')->assertOk()->getContent();

        expect($html)->toMatch('/<a\s+href="#quote"[^>]*data-lead-modal="product" data-product-id="'.$product->id.'"/')
            ->toContain('id="quote"')
            // The inline product form stays as the no-JS fallback.
            ->toContain('id="lead-phone"');
    });

    it('never marks external, link or cart CTAs', function () {
        foreach ([ProductCta::ACTION_EXTERNAL, ProductCta::ACTION_LINK, ProductCta::ACTION_CART] as $action) {
            $cta = new ProductCta(ProductCtaKind::Contact, 'site.cta.contact', $action, 'https://example.test/x', 5, true);

            expect(Blade::render('<x-site.product-cta :cta="$cta" />', ['cta' => $cta]))->not->toContain('data-lead-modal');
        }
    });

    it('keeps the ProductCta array shape for JSON clients', function () {
        $product = Product::factory()->quoteOnly()->create();

        expect(array_keys(ProductCtas::for($product)[0]->toArray()))->toBe(['kind', 'label_key', 'action', 'url', 'product_id', 'primary']);
    });

    it('intercepts clicks only once Livewire and the modal are there, and forgets rolls of earlier pages', function () {
        $js = file_get_contents(resource_path('js/lead-modal.js'));

        expect(file_get_contents(resource_path('js/app.js')))->toContain("import './lead-modal';")
            ->and($js)->toContain("import { opensLeadModal } from './lead-modal-click';")
            ->toContain('!opensLeadModal(event, trigger)')
            ->toContain('!window.Livewire')
            ->toContain('[data-lead-modal-root][data-lead-modal-ready]')
            ->toContain("addEventListener('livewire:navigating', () => lastRolls.clear())")
            ->toContain('roll.path === window.location.pathname')
            ->not->toContain('innerHTML');
    });
});
