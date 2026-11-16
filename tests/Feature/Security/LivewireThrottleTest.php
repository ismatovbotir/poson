<?php

use App\Enums\Role;
use App\Models\User;
use App\Support\Http\LivewireThrottle;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * T7: throttle on POST /livewire-{hash}/update (limiter "livewire").
 */
function livewireLimit(?User $user, string $ip = '203.0.113.5', array $headers = []): Limit
{
    $request = Request::create('/livewire-x/update', 'POST', server: ['REMOTE_ADDR' => $ip] + collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());
    $request->setUserResolver(fn () => $user);

    return LivewireThrottle::limit($request);
}

function livewireUpdatePath(): string
{
    return '/'.Route::getRoutes()->getByName('default-livewire.update')->uri();
}

it('keeps the update route on web, RequireLivewireHeaders and the livewire throttle', function () {
    $route = Route::getRoutes()->getByName('default-livewire.update');
    $middleware = $route->gatherMiddleware();

    expect($route->uri())->toMatch('#^livewire-[a-z0-9]+/update$#')
        ->and($middleware)->toContain('web')
        ->toContain(RequireLivewireHeaders::class)
        ->toContain('throttle:livewire');
});

it('limits guests to 180 per minute by IP', function () {
    $limit = livewireLimit(null);

    expect($limit->maxAttempts)->toBe(180)->and($limit->key)->toBe('lw:ip:203.0.113.5')
        ->and(livewireLimit(null, '203.0.113.6')->key)->not->toBe($limit->key);
});

it('shares one key inside an IPv6 /64 and splits different networks', function () {
    $a = livewireLimit(null, '2001:db8:1:2:aaaa::1')->key;

    expect(livewireLimit(null, '2001:db8:1:2:bbbb::9')->key)->toBe($a)
        ->and(livewireLimit(null, '2001:db8:1:3::1')->key)->not->toBe($a);
});

it('limits an active staff user to 600 per minute by user id', function () {
    $user = staffUser(Role::SalesManager);

    $limit = livewireLimit($user);

    expect($limit->maxAttempts)->toBe(600)->and($limit->key)->toBe('lw:u:'.$user->id);
});

it('treats a deactivated staff user and a customer like any guest', function () {
    $inactive = staffUser(Role::ContentManager);
    $inactive->forceFill(['is_active' => false])->save();
    $customer = User::factory()->create();

    foreach ([$inactive->fresh(), $customer] as $user) {
        $limit = livewireLimit($user);

        expect($limit->maxAttempts)->toBe(180)->and($limit->key)->toBe('lw:ip:203.0.113.5');
    }
});

it('ignores a spoofed X-Forwarded-For from an untrusted proxy', function () {
    config(['app.trusted_proxies' => []]);

    $real = livewireLimit(null, '203.0.113.5')->key;
    $spoofed = livewireLimit(null, '203.0.113.5', ['X-Forwarded-For' => '198.51.100.77'])->key;

    expect($spoofed)->toBe($real);
});

it('answers 429 with Retry-After after 180 guest requests and keeps other IPs working', function () {
    $post = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson(livewireUpdatePath(), ['components' => []], ['X-Livewire' => '1']);

    foreach (range(1, 180) as $i) {
        expect($post('203.0.113.50')->status())->not->toBe(429);
    }

    $blocked = $post('203.0.113.50');

    expect($blocked->status())->toBe(429)->and($blocked->headers->get('Retry-After'))->not->toBeNull()
        ->and($post('203.0.113.51')->status())->not->toBe(429);
});

it('renders a localized minimal 429 page', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60']);

    foreach (['uz' => 'Juda koʻp soʻrov', 'ru' => 'Слишком много запросов', 'en' => 'Too many requests'] as $locale => $title) {
        $html = view('errors.429', ['exception' => new TooManyRequestsHttpException(30)])->render();
        app()->setLocale($locale);
        $html = view('errors.429', ['exception' => new TooManyRequestsHttpException(30)])->render();

        expect($html)->toContain($title)->toContain('30');
    }
});

it('prints the localized toast template in the site layout', function () {
    $this->get('/ru')->assertOk()->assertSee('name="livewire-throttle"', false)->assertSee('{seconds}', false);
});

/**
 * Exhausts the "livewire" limiter for the next request so the update route answers 429.
 */
function throttleLivewireNow(): void
{
    RateLimiter::for('livewire', fn () => Limit::perMinute(1)->by('lw:test'));
}

it('renders the 429 of the update route in the locale from the cookie', function () {
    throttleLivewireNow();
    $post = fn () => $this->withCookie('locale', 'ru')->post(livewireUpdatePath(), ['components' => []], ['X-Livewire' => '1']);

    $post();
    $blocked = $post();

    expect($blocked->status())->toBe(429)
        ->and($blocked->getContent())->toContain('Слишком много запросов')->toContain('lang="ru"');
});

it('falls back to the default locale for a tampered locale cookie and never echoes it', function () {
    throttleLivewireNow();
    $post = fn () => $this->withCookie('locale', 'xx"><script>')->post(livewireUpdatePath(), ['components' => []], ['X-Livewire' => '1']);

    $post();
    $blocked = $post();

    expect($blocked->status())->toBe(429)
        ->and($blocked->getContent())->toContain('lang="'.config('app.locale').'"')
        ->toContain(__('site.throttle.title', [], config('app.locale')))
        ->not->toContain('script')->not->toContain('xx"');
});

it('keys a guest by the client IP from a trusted proxy', function () {
    config(['app.trusted_proxies' => ['127.0.0.1']]);
    Route::post('/_lw-throttle-key-probe', fn (Request $request) => LivewireThrottle::limit($request)->key);

    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->post('/_lw-throttle-key-probe', [], ['X-Forwarded-For' => '198.51.100.77'])
        ->assertOk()
        ->assertSeeText('lw:ip:198.51.100.77');
});
