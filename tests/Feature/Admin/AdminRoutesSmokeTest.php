<?php

use App\Enums\AgentRole;
use App\Models\Attribute;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\BusinessChat;
use App\Models\Category;
use App\Models\ChatRule;
use App\Models\Customer;
use App\Models\IntegrationSecret;
use App\Models\JobApplication;
use App\Models\Lead;
use App\Models\NewsPost;
use App\Models\Order;
use App\Models\Page;
use App\Models\PriceSyncLog;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\Quote;
use App\Models\Repair;
use App\Models\RollType;
use App\Models\Setting;
use App\Models\TelegramGroup;
use App\Models\TelegramUser;
use App\Models\Vacancy;
use App\Models\WebChat;
use App\Support\Chat\ChatSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

const SMOKE_FAKE_SECRET = 'fake-uzqr-SMOKESECRET-0000000000wxyz';

/**
 * Every admin GET route of the application. Livewire page routes are served
 * by vendor classes, so `route:list --except-vendor` hides them; the filter
 * here is therefore "named admin.*, GET, not logout / webhook / vendor internals".
 *
 * @return array<string, LaravelRoute>
 */
function smokeAdminGetRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'admin.') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (str_contains($name, 'logout') || str_contains($name, 'webhook') || str_starts_with($route->uri(), 'livewire')) {
            continue;
        }

        $routes[$name] = $route;
    }

    return $routes;
}

/**
 * Route name => closure creating the route parameters with factories.
 * A parameterised admin GET route that is not listed here fails the test.
 *
 * @return array<string, Closure(): array<string, mixed>>
 */
function smokeParameterMap(): array
{
    $article = fn () => ['newsPost' => NewsPost::factory()->article()->create()];

    return [
        'admin.media.original' => fn () => ['media' => Brand::factory()->create()->addMedia(UploadedFile::fake()->image('a.png'))->toMediaCollection('logo')],
        'admin.leads.show' => fn () => ['lead' => Lead::factory()->create()],
        'admin.leads.edit' => fn () => ['lead' => Lead::factory()->create()],
        'admin.customers.show' => fn () => ['customer' => Customer::factory()->create()],
        'admin.customers.edit' => fn () => ['customer' => Customer::factory()->create()],
        'admin.repairs.show' => fn () => ['repair' => Repair::factory()->create()],
        'admin.repairs.edit' => fn () => ['repair' => Repair::factory()->create()],
        'admin.repairs.receipt' => fn () => ['repair' => Repair::factory()->create()],
        'admin.quotes.show' => fn () => ['quote' => Quote::factory()->create()],
        'admin.quotes.edit' => fn () => ['quote' => Quote::factory()->create()],
        'admin.quotes.pdf' => fn () => ['quote' => Quote::factory()->create()],
        'admin.orders.show' => fn () => ['order' => Order::factory()->create()],
        'admin.orders.edit' => fn () => ['order' => Order::factory()->create()],
        'admin.orders.invoice' => fn () => ['order' => Order::factory()->bankTransfer()->create()],
        'admin.categories.edit' => fn () => ['category' => Category::factory()->create()],
        'admin.attributes.edit' => fn () => ['attribute' => Attribute::factory()->create()],
        'admin.brands.edit' => fn () => ['brand' => Brand::factory()->create()],
        'admin.roll-types.edit' => fn () => ['rollType' => RollType::factory()->create()],
        'admin.careers.vacancies.edit' => fn () => ['vacancy' => Vacancy::factory()->create()],
        'admin.careers.applications.show' => fn () => ['jobApplication' => JobApplication::factory()->create()],
        'admin.careers.applications.cv' => fn () => ['jobApplication' => JobApplication::factory()->create()],
        'admin.products.edit' => fn () => ['product' => Product::factory()->create()],
        'admin.products.ai.improve' => fn () => ['product' => Product::factory()->create()],
        'admin.products.documents.download' => function () {
            $document = ProductDocument::factory()->file()->create();
            Storage::disk(ProductDocument::DISK)->put($document->path, '%PDF-1.4 fake');

            return ['document' => $document];
        },
        'admin.price-sync.show' => fn () => ['priceSyncLog' => PriceSyncLog::factory()->create()],
        'admin.pages.edit' => fn () => ['page' => Page::factory()->create()],
        'admin.news.edit' => fn () => ['newsPost' => NewsPost::factory()->create()],
        'admin.articles.edit' => $article,
        'admin.banners.edit' => fn () => ['banner' => Banner::factory()->create()],
        'admin.chatbot.rules.edit' => fn () => ['rule' => ChatRule::factory()->create()],
        'admin.telegram.chats.web.show' => fn () => ['webChat' => WebChat::factory()->create()],
        'admin.telegram.chats.show' => fn () => ['telegramUser' => TelegramUser::factory()->create()],
        'admin.office.chats.show' => fn () => ['businessChat' => BusinessChat::factory()->client()->create()],
        'admin.office.groups.show' => fn () => ['group' => TelegramGroup::factory()->tracked()->create()],
        'admin.office.education' => fn () => ['role' => AgentRole::Router->value],
    ];
}

/**
 * Routes that redirect an already authenticated Admin (every other route must answer 200).
 *
 * @return list<string>
 */
function smokeRedirectRoutes(): array
{
    return ['admin.login', 'admin.service-requests.index'];
}

/**
 * Routes that stream a file or a PDF: no page chrome, and a missing file is a 404, not a bug.
 *
 * @return list<string>
 */
function smokeFileRoutes(): array
{
    return [
        'admin.media.original', 'admin.careers.applications.cv', 'admin.orders.invoice',
        'admin.products.documents.download', 'admin.quotes.pdf', 'admin.repairs.receipt',
    ];
}

/**
 * Pages on the centred guest layout (login, confirm password, forced password change): kept narrow on purpose.
 *
 * @return list<string>
 */
function smokeGuestLayoutRoutes(): array
{
    return ['admin.login', 'admin.account.password', 'admin.confirm-password'];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
});

it('renders every admin GET route as an Admin without a 500 and with a fluid main', function () {
    $admin = staffUser();

    // The AI wizard pages are 403 while the Admin-only switch is off (default); turn it on with a fake key.
    config(['services.anthropic.key' => 'fake-anthropic-key-for-tests']);
    Setting::query()->create(['key' => ChatSettings::key('ai_admin_enabled'), 'group' => 'chatbot', 'value' => true]);
    ChatSettings::forget();

    IntegrationSecret::factory()->withValue('uzqr.api_key', SMOKE_FAKE_SECRET)->create();
    freshSecrets();
    confirmAdminPassword();

    $routes = smokeAdminGetRoutes();
    $map = smokeParameterMap();
    $fileRoutes = smokeFileRoutes();
    $problems = [];
    $covered = 0;

    foreach ($routes as $name => $route) {
        $needsParameters = $route->parameterNames() !== [];

        if ($needsParameters && ! isset($map[$name])) {
            $problems[] = "{$name} ({$route->uri()}) takes parameters but has no entry in smokeParameterMap()";

            continue;
        }

        try {
            $parameters = $needsParameters ? $map[$name]() : [];
            $response = $this->actingAs($admin)->get(route($name, $parameters));
        } catch (Throwable $e) {
            $problems[] = "{$name}: ".$e::class.': '.str($e->getMessage())->limit(200);

            continue;
        }

        $status = $response->getStatusCode();
        $isFile = in_array($name, $fileRoutes, true);
        $allowed = in_array($name, smokeRedirectRoutes(), true) ? [302] : [200];

        // The CV is fetched live from Telegram and is a deliberate 404 unless forwarded; no outbound call in a smoke test.
        if ($name === 'admin.careers.applications.cv') {
            $allowed = [404];
        }

        if (! in_array($status, $allowed, true)) {
            $problems[] = "{$name}: unexpected HTTP {$status}";

            continue;
        }

        if ($status === 200 && ! $isFile && ! in_array($name, smokeGuestLayoutRoutes(), true)) {
            $html = $response->getContent();
            $body = preg_replace('#<(div|section|aside)\b[^>]*role="(?:alert)?dialog"[^>]*>.*?</\1>#s', '', $html) ?? $html;

            if (preg_match('/class="[^"]*\b(?<![:\w-])max-w-(?:3xl|4xl)\b/', $body)) {
                $problems[] = "{$name}: a page-level max-w-3xl / max-w-4xl cap is back";
            }

            if (! preg_match('/<main id="main"([^>]*)>/', $html, $main)) {
                $problems[] = "{$name}: no <main id=\"main\"> in the page";
            } elseif (preg_match('/class="[^"]*\bmax-w-/', $main[1])) {
                $problems[] = "{$name}: <main> still has a max-w- class";
            }
        }

        $covered++;
    }

    expect($problems)->toBe([], implode("\n", $problems))
        ->and($covered)->toBe(count($routes))
        ->and($covered)->toBeGreaterThanOrEqual(95);
});

it('has no stale entries in the parameter map', function () {
    expect(array_diff(array_keys(smokeParameterMap()), array_keys(smokeAdminGetRoutes())))->toBe([]);
});

it('shows only the masked value of a stored secret on the Integrations page', function () {
    IntegrationSecret::factory()->withValue('uzqr.api_key', SMOKE_FAKE_SECRET)->create();
    freshSecrets();
    confirmAdminPassword();

    $html = $this->actingAs(staffUser())->get(route('admin.settings.integrations'))->assertOk()->getContent();

    expect($html)->toContain('••••wxyz')
        ->and($html)->not->toContain('SMOKESECRET')
        ->and($html)->not->toContain(SMOKE_FAKE_SECRET);
});
