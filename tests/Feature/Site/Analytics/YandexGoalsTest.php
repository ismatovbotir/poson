<?php

use App\Enums\AnalyticsGoal;
use App\Enums\ProductType;
use App\Enums\Role;
use App\Livewire\Site\Cart\QuoteForm as CartQuoteForm;
use App\Livewire\Site\Checkout\Page as CheckoutPage;
use App\Livewire\Site\LeadForm;
use App\Livewire\Site\Services\RequestPage;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Repair;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vacancy;
use App\Support\HtmlSanitizer;
use App\Support\Site\Analytics;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../Helpers/Careers.php';

/**
 * Plan 40 T1/T2: Metrika goals (YD-1 server side, YD-2, YD-3, YD-4, YD-5).
 */
function ydTracking(bool $on = true, bool $force = true): void
{
    Setting::factory()->setting('tracking', ['enabled' => $on, 'ga4' => '', 'metrika' => '98765432'])->create();
    config(['site.analytics_force' => $force]);
}

/**
 * The "poson-goal" (or other) browser events a component dispatched, with their params.
 *
 * @return list<array<string, mixed>>
 */
function ydDispatched(Testable $component, string $event): array
{
    return collect(data_get($component->effects, 'dispatches', []))
        ->where('name', $event)
        ->map(fn (array $dispatch): array => $dispatch['params'])
        ->values()
        ->all();
}

function ydFill(Testable $component, array $fields): Testable
{
    foreach ($fields as $field => $value) {
        $component->set($field, $value);
    }

    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component;
}

beforeEach(function () {
    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);
});

describe('YD-3 goals from the lead forms', function () {
    it('sends lead_callback once, with only the goal key', function () {
        ydTracking();

        $component = ydFill(Livewire::test(LeadForm::class), ['name' => 'Aziz', 'phone' => '90 123 45 67'])->call('submit');

        expect(ydDispatched($component, 'poson-goal'))->toBe([['goal' => 'lead_callback']])
            ->and(Lead::query()->count())->toBe(1);
    });

    it('sends lead_product_quote / lead_product_service by the product type', function (ProductType $type, string $goal) {
        ydTracking();
        $product = Product::factory()->create(['type' => $type]);

        $component = ydFill(Livewire::test(LeadForm::class, ['productId' => $product->id]), ['name' => 'Aziz', 'phone' => '90 123 45 67'])->call('submit');

        expect(ydDispatched($component, 'poson-goal'))->toBe([['goal' => $goal]]);
    })->with([
        'hardware' => [ProductType::Hardware, 'lead_product_quote'],
        'service' => [ProductType::Service, 'lead_product_service'],
    ]);

    it('sends lead_cart_quote from the cart page', function () {
        ydTracking();
        orderCart();

        $component = ydFill(Livewire::test(CartQuoteForm::class), ['name' => 'Aziz', 'phone' => '90 123 45 67'])->call('requestQuote');

        expect(ydDispatched($component, 'poson-goal'))->toBe([['goal' => 'lead_cart_quote']]);
    });

    it('sends lead_service_request from the service request page', function () {
        ydTracking();

        $component = ydFill(Livewire::test(RequestPage::class), [
            'name' => 'Aziz', 'phone' => '90 123 45 67', 'device_brand' => 'Zebra', 'device_model' => 'ZD421',
            'message' => 'The printer does not feed labels.', 'preferred_contact' => 'telegram',
        ])->call('submit');

        expect(ydDispatched($component, 'poson-goal'))->toBe([['goal' => 'lead_service_request']]);
    });

    it('sends nothing for staff, outside production, with tracking off, for bots or on validation errors', function (Closure $setup, array $fields) {
        $setup();

        $component = ydFill(Livewire::test(LeadForm::class), $fields)->call('submit');

        expect(ydDispatched($component, 'poson-goal'))->toBe([]);
    })->with([
        'staff' => [function () {
            ydTracking();
            test()->actingAs(staffUser(Role::SalesManager));
        }, ['name' => 'Aziz', 'phone' => '90 123 45 67']],
        'non-production without force' => [fn () => ydTracking(force: false), ['name' => 'Aziz', 'phone' => '90 123 45 67']],
        'tracking off' => [fn () => ydTracking(on: false), ['name' => 'Aziz', 'phone' => '90 123 45 67']],
        'bot (honeypot)' => [fn () => ydTracking(), ['name' => 'Aziz', 'phone' => '90 123 45 67', 'fax_ext' => 'spam']],
        'validation failure' => [fn () => ydTracking(), ['name' => '', 'phone' => '12']],
    ]);

    it('sends goals to a signed-in non-staff customer in production', function () {
        ydTracking(force: false);
        app()->detectEnvironment(fn (): string => 'production');
        $this->actingAs(User::factory()->create());

        $component = ydFill(Livewire::test(LeadForm::class), ['name' => 'Aziz', 'phone' => '90 123 45 67'])->call('submit');

        expect(ydDispatched($component, 'poson-goal'))->toBe([['goal' => 'lead_callback']]);
    });
});

describe('YD-1 / YD-2 order_placed navigation', function () {
    beforeEach(fn () => config(['site.cart_enabled' => true]));

    it('with goals on: no redirect, placedUrl and a fallback link, poson-order-placed with a relative /o path', function () {
        ydTracking();
        orderCart();

        $component = ydFill(Livewire::test(CheckoutPage::class), ['name' => 'Aziz Karimov', 'phone' => '90 123 45 67'])->call('placeOrder');

        $order = Order::query()->sole();
        $events = ydDispatched($component, 'poson-order-placed');

        $component->assertHasNoErrors()->assertNoRedirect()
            ->assertSet('placedUrl', '/o/'.$order->ulid.'?lang=en')
            ->assertSeeHtml('data-order-placed-link')
            ->assertSeeHtml('class="ym-disable-tracklink')
            ->assertSeeHtml('href="/o/'.$order->ulid.'?lang=en"')
            ->assertSee(__('checkout.placed.link'));

        expect($events)->toHaveCount(1)
            ->and($events[0])->toBe(['goal' => 'order_placed', 'next' => '/o/'.$order->ulid.'?lang=en'])
            ->and($events[0]['next'])->toStartWith('/o/')->not->toContain('://')->not->toContain('//localhost')
            ->and($events[0]['next'])->toMatch('#^/o/[0-7][0-9A-HJKMNP-TV-Z]{25}\?lang=en$#')
            ->and(ydDispatched($component, 'poson-goal'))->toBe([]);
    });

    it('a second click sends the buyer on again without a second order', function () {
        ydTracking();
        orderCart();

        $component = ydFill(Livewire::test(CheckoutPage::class), ['name' => 'Aziz Karimov', 'phone' => '90 123 45 67'])->call('placeOrder');
        $next = '/o/'.Order::query()->sole()->ulid.'?lang=en';

        expect(ydDispatched($component, 'poson-order-placed'))->toBe([['goal' => 'order_placed', 'next' => $next]]);

        // The replay navigates only: no goal key, so order_placed is counted once.
        $component->call('placeOrder');

        expect(Order::query()->count())->toBe(1)
            ->and(ydDispatched($component, 'poson-order-placed'))->toBe([['next' => $next]]);
    });

    it('with goals off: the redirect to /o is unchanged', function () {
        ydTracking(on: false);
        orderCart();

        $component = ydFill(Livewire::test(CheckoutPage::class), ['name' => 'Aziz Karimov', 'phone' => '90 123 45 67'])->call('placeOrder');

        $order = Order::query()->sole();
        $component->assertRedirect(route('orders.public', ['ulid' => $order->ulid, 'lang' => 'en']))->assertSet('placedUrl', null);

        expect(ydDispatched($component, 'poson-order-placed'))->toBe([]);
    });

    it('registers the order_placed listener in app.js whatever the analytics config', function () {
        expect(file_get_contents(resource_path('js/app.js')))->toContain("import './order-placed';")
            ->and(file_get_contents(resource_path('js/order-placed.js')))
            ->toContain("addEventListener('poson-order-placed'")
            ->toContain('GOAL_TIMEOUT_MS = 1500')
            ->toContain('safeOrderUrl(detail.next, window.location.origin)')
            ->toContain('window.location.assign(target)')
            // Once-only guard: a replayed event never sends order_placed again.
            ->toContain('let orderGoalSent = false;')
            ->toContain("const wantsGoal = detail.goal === 'order_placed' && !orderGoalSent;")
            ->toContain('orderGoalSent = true;');
    });
});

describe('YD-4 careers goal meta', function () {
    it('flashes careers_application for a stored application and prints exactly one meta', function () {
        ydTracking();
        $vacancy = Vacancy::factory()->published()->create();

        postVacancyApplication($vacancy)->assertSessionHas(Analytics::GOAL_FLASH, 'careers_application');

        $html = $this->withSession([Analytics::GOAL_FLASH => 'careers_application'])->get('/uz')->assertOk()->getContent();

        expect(substr_count($html, '<meta name="poson-goal" content="careers_application">'))->toBe(1);
    });

    it('flashes nothing for a bot', function () {
        ydTracking();
        $vacancy = Vacancy::factory()->published()->create();

        postVacancyApplication($vacancy, ['fax_ext' => 'x'])->assertSessionMissing(Analytics::GOAL_FLASH);
    });

    it('prints no meta for an unknown or injected value, or while goals are off', function (mixed $value, bool $tracking) {
        ydTracking(on: $tracking);

        $html = $this->withSession([Analytics::GOAL_FLASH => $value])->get('/uz')->assertOk()->getContent();

        expect($html)->not->toContain('<meta name="poson-goal"')->not->toContain('<script>alert');
    })->with([
        'injected' => ['"><script>alert(1)</script>', true],
        'unknown' => ['lead_everything', true],
        'array' => [['careers_application'], true],
        'valid but tracking off' => ['careers_application', false],
    ]);
});

describe('YD-3 delegated click goals', function () {
    it('tags only the company Telegram links, with the allow-listed goal', function () {
        Setting::factory()->setting('social', [['network' => 'telegram', 'url' => 'https://t.me/poson_uz']])->create();

        $html = $this->get('/uz')->assertOk()->getContent();

        expect($html)->toContain('data-poson-goal="click_telegram"')
            ->and(preg_match_all('/data-poson-goal="([^"]*)"/', $html, $matches))->toBeGreaterThan(0)
            ->and(array_unique($matches[1]))->toBe(['click_telegram']);
    });

    it('keeps the JS allow-list and the goal name check', function () {
        $js = file_get_contents(resource_path('js/analytics.js'));

        expect($js)->toContain("const CLICK_GOALS = ['click_telegram'];")
            ->toContain('export const GOAL = /^[a-z][a-z0-9_]{2,39}$/;')
            ->toContain("window.ym(Number(config.metrika), 'reachGoal', goal);")
            ->not->toContain('innerHTML')
            ->not->toContain('eval(');
    });

    it('strips data-poson-goal from rich text (the sanitizer allows no data-* attributes)', function () {
        expect(HtmlSanitizer::clean('<p><a href="https://t.me/x" data-poson-goal="click_telegram">x</a></p>'))->not->toContain('data-poson-goal');
    });

    it('lists every goal ID once with the ID format the JS accepts', function () {
        foreach (AnalyticsGoal::cases() as $goal) {
            expect($goal->value)->toMatch('/^[a-z][a-z0-9_]{2,39}$/');
        }

        expect(AnalyticsGoal::CareersApplication->isAdGoal())->toBeFalse()
            ->and(AnalyticsGoal::CareersApplication->directRole())->toBe('never')
            ->and(AnalyticsGoal::offline())->toBe([AnalyticsGoal::OrderPaid, AnalyticsGoal::QuoteAccepted]);
    });
});

describe('YD-5 token pages and admin stay clean', function () {
    beforeEach(function () {
        ydTracking();
        config(['site.cart_enabled' => true]);
    });

    it('loads no analytics meta, goal meta or app.js on token pages', function (Closure $url, bool $noReferrer) {
        $response = $this->withSession([Analytics::GOAL_FLASH => 'careers_application'])->get($url());
        $html = (string) $response->getContent();

        expect($html)->not->toContain('poson-analytics')
            ->not->toContain('<meta name="poson-goal"')
            ->and(preg_match('#/build/assets/app-[A-Za-z0-9_-]+\.js#', $html))->toBe(0);

        if ($noReferrer) {
            expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
        }
    })->with([
        '/o' => [fn () => route('orders.public', Order::factory()->create(['public_until' => now()->addDays(10)])->ulid), true],
        '/q' => [fn () => '/q/'.Quote::factory()->sent()->create()->ulid, true],
        '/r' => [fn () => '/r/'.Repair::factory()->withStatusToken()->create()->public_token, true],
        '/o missing' => [fn () => '/o/01JD3Z8Q4X5V6W7Y8Z9A0B1C2D', false],
        '/q missing' => [fn () => '/q/01JD3Z8Q4X5V6W7Y8Z9A0B1C2D', false],
        '/r missing' => [fn () => '/r/not-a-real-token', false],
    ]);

    it('loads no analytics in the admin panel', function (string $route) {
        $this->actingAs(staffUser())->get(route($route))->assertOk()
            ->assertDontSee('poson-analytics', false)
            ->assertDontSee('<meta name="poson-goal"', false);
    })->with(['admin.dashboard', 'admin.analytics.campaigns', 'admin.settings.company']);
});
