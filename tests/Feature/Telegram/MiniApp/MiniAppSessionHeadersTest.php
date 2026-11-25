<?php

use App\Http\Middleware\ConfigureTmaSession;
use App\Models\Order;
use App\Models\SiteVisit;
use App\Support\Telegram\MiniApp\TmaSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 M1: the Mini App session (MA-5, MA-6), headers (MA-3, MA-16, MA-18)
 * and framing of everything else (MA-4).
 */
beforeEach(function () {
    $this->withoutVite();
    tmaEnabled();
});

function tmaExpectedCsp(): string
{
    return "default-src 'self'; script-src https://telegram.org/js/telegram-web-app.js 'self'; style-src 'self'; img-src 'self' data:; "
        ."connect-src http://localhost/tma/; form-action http://localhost/tma/; frame-src 'none'; worker-src 'none'; manifest-src 'none'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors https://web.telegram.org";
}

it('sends the exact CSP, noindex and no-store on every Mini App response, and no X-Frame-Options (MA-3)', function (string $url) {
    $response = $this->get($url);

    expect($response->headers->get('Content-Security-Policy'))->toBe(tmaExpectedCsp())
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->has('X-Frame-Options'))->toBeFalse();
})->with(['/tma', '/tma/uz/unavailable/expired', '/tma/uz', '/tma/uz/p/no-such-product']);

it('sets only the Mini App cookies, at path /tma, Secure, SameSite=None, Partitioned (MA-5)', function () {
    $cookies = collect($this->get('/tma')->headers->getCookies())->keyBy(fn (Cookie $cookie) => $cookie->getName());

    expect($cookies->keys()->sort()->values()->all())->toBe(collect([ConfigureTmaSession::COOKIE, 'XSRF-TOKEN'])->sort()->values()->all());

    foreach ($cookies as $cookie) {
        expect($cookie->getPath())->toBe('/tma')
            ->and($cookie->isSecure())->toBeTrue()
            ->and($cookie->getSameSite())->toBe('none')
            ->and($cookie->isPartitioned())->toBeTrue();
    }

    expect($cookies[ConfigureTmaSession::COOKIE]->isHttpOnly())->toBeTrue();
});

it('never touches the site or admin session cookie and restores the session config (MA-5)', function () {
    $siteCookie = config('session.cookie');

    $tma = $this->get('/tma');
    $names = collect($tma->headers->getCookies())->map(fn (Cookie $cookie) => $cookie->getName())->all();

    expect($names)->not->toContain($siteCookie)
        ->and(config('session.cookie'))->toBe($siteCookie)
        ->and(config('session.path'))->toBe('/')
        ->and(config('session.same_site'))->toBe('lax')
        ->and(app('session')->driver()->getName())->toBe($siteCookie);

    $site = collect($this->get('/uz')->headers->getCookies())->keyBy(fn (Cookie $cookie) => $cookie->getName());
    expect($site->has($siteCookie))->toBeTrue()
        ->and($site[$siteCookie]->getPath())->toBe('/')
        ->and($site->has(ConfigureTmaSession::COOKIE))->toBeFalse();

    $admin = collect($this->get('/admin/login')->headers->getCookies())->map(fn (Cookie $cookie) => $cookie->getName())->all();
    expect($admin)->toContain($siteCookie)->not->toContain(ConfigureTmaSession::COOKIE);
});

it('enforces CSRF on Mini App POSTs (no exemptions)', function () {
    $previous = app()['env'];
    app()['env'] = 'production';

    try {
        // Production answers APP_URL's host only (trustHosts).
        $this->postJson('https://poson.example.uz/tma/auth', ['init_data' => tmaInitData(tmaUser()->telegram_id)])->assertStatus(419);
        $user = tmaUser(['phone' => '+998907654321']);
        tmaSignIn($user);
        $this->post('https://poson.example.uz/tma/uz/cart', ['product_id' => 1, 'qty' => 1])->assertStatus(419);
    } finally {
        Request::setTrustedHosts([]);
        app()['env'] = $previous;
    }
});

it('lets a fresh session in and re-checks the user on every request (MA-6, MA-13)', function () {
    $user = tmaUser();
    tmaSignIn($user);

    $this->get('/tma/uz')->assertOk();

    $user->forceFill(['banned_at' => now()])->save();
    $this->get('/tma/uz')->assertRedirect(route('tma.bootstrap'));
    expect(session(TmaSession::USER))->toBeNull();
});

it('sends a stale or foreign session back to the sign-in', function (Closure $setup) {
    $user = tmaUser();
    $setup($user);

    $this->get('/tma/uz/orders')->assertRedirect(route('tma.bootstrap', ['next' => 'orders']));
})->with([
    'no session' => [fn () => null],
    'idle over 2 h' => [fn ($user) => tmaSignIn($user, seenAt: now()->subMinutes(121)->getTimestamp())],
    'absolute over 12 h' => [fn ($user) => tmaSignIn($user, authAt: now()->subHours(12)->subMinute()->getTimestamp())],
    'other telegram id' => [fn ($user) => test()->withSession([TmaSession::USER => $user->id, TmaSession::TELEGRAM_ID => 1, TmaSession::AUTH_AT => now()->getTimestamp(), TmaSession::SEEN_AT => now()->getTimestamp()])],
    'blocked' => [function ($user) {
        $user->forceFill(['blocked_at' => now()])->save();
        tmaSignIn($user);
    }],
    'unregistered' => [function ($user) {
        $user->forceFill(['registered_at' => null])->save();
        tmaSignIn($user);
    }],
    'switch off' => [function ($user) {
        tmaEnabled(miniApp: false);
        tmaSignIn($user);
    }],
    'bot off' => [function ($user) {
        tmaEnabled(bot: false);
        tmaSignIn($user);
    }],
]);

it('renders Mini App HTML without Livewire, widget, analytics, inline script or style (MA-16, MA-18)', function (string $url) {
    $user = tmaUser();
    tmaSignIn($user);

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->not->toContain('livewire')
        ->not->toContain('wire:')
        ->not->toContain('x-data')
        ->not->toContain('web-chat')
        ->not->toContain('gtag')
        ->not->toContain('googletagmanager')
        ->not->toContain('mc.yandex')
        ->not->toContain('style=')
        ->not->toMatch('/<style\b/i')
        ->and(preg_match_all('/<script\b[^>]*>/i', $html, $scripts))->toBe(1)
        ->and($scripts[0][0])->toBe('<script src="https://telegram.org/js/telegram-web-app.js">');
})->with(['/tma', '/tma/uz', '/tma/uz/orders', '/tma/uz/search?q=zebra', '/tma/uz/unavailable/phone']);

it('loads telegram-web-app.js only in the Mini App layout (MA-16)', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_contains($file->getContents(), 'telegram-web-app.js'))
        ->map(fn ($file) => str_replace(resource_path('views').'/', '', $file->getPathname()))
        ->values()->all();

    expect($offenders)->toBe(['layouts/tma.blade.php'])
        ->and($this->get('/uz')->getContent())->not->toContain('telegram-web-app.js');
});

it('denies framing of site, admin and customer links (MA-4)', function (string $url) {
    $order = Order::factory()->create();
    $response = $this->get(str_replace('{ulid}', $order->ulid, $url));

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'");
})->with(['/uz', '/admin/login', '/o/{ulid}', '/o/01ARZ3NDEKTSV4RRFFQ69G5FAV', '/q/01ARZ3NDEKTSV4RRFFQ69G5FAV', '/r/unknown-token', '/robots.txt']);

it('keeps an existing policy (the CV download keeps its sandbox) and still denies framing (MA-4)', function () {
    Route::middleware('web')->get('/__tma-test-csp', fn () => response('x')->header('Content-Security-Policy', "default-src 'none'; sandbox"));

    $response = $this->get('/__tma-test-csp');

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('keeps /tma out of robots.txt and analytics', function () {
    openSearchIndexing();

    expect($this->get('/robots.txt')->getContent())->toContain('Disallow: /tma/')
        ->and(config('analytics.exclude_paths'))->toContain('tma', 'tma/*');

    $this->get('/tma');
    expect(SiteVisit::query()->count())->toBe(0);
});
