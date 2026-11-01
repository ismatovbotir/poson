<?php

use App\Enums\ApiTokenType;
use App\Enums\McpCallStatus;
use App\Enums\Role;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateMcpToken;
use App\Http\Middleware\EnsureMcpUser;
use App\Models\ApiToken;
use App\Models\BotHit;
use App\Models\McpCall;
use App\Models\SiteVisit;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/*
| Plan 13 delta sign-off C2, C4–C7, C11, C14, C17, C30 (abuse cases).
*/

/**
 * The exact challenge of a 401 (laravel/mcp's AddWwwAuthenticateHeader; no OAuth metadata URL).
 */
const MCP_WWW_AUTHENTICATE = 'Bearer realm="mcp", error="invalid_token"';

beforeEach(function () {
    mcpTelegramConfigured();
});

it('answers a valid holder token', function () {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue());

    mcpRequest($plain)->assertOk()->assertJsonPath('result.tools.0.name', 'get_my_access');

    expect($token->fresh()->last_used_at)->not->toBeNull()
        ->and($token->fresh()->last_used_ip)->toBe('127.0.0.1');
});

it('refuses missing, malformed, unknown, revoked and expired tokens with 401', function (?string $token) {
    mcpHolder(fakeMcpTokenValue());

    mcpRequest($token)->assertUnauthorized()->assertHeader('WWW-Authenticate', MCP_WWW_AUTHENTICATE)->assertJsonPath('error', 'unauthenticated');
})->with([
    'no token' => [null],
    'malformed' => ['posm_short'],
    'unknown' => [fakeMcpTokenValue('zZ99')],
    'price api shape' => [fakeApiTokenValue()],
]);

it('refuses a revoked MCP token, logs a denied row and alerts once per hour', function () {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue());
    $token->forceFill(['revoked_at' => now()])->save();

    mcpRequest($plain)->assertUnauthorized()->assertHeader('WWW-Authenticate', MCP_WWW_AUTHENTICATE);
    mcpRequest($plain)->assertUnauthorized();

    expect(McpCall::query()->where('status', McpCallStatus::Denied)->count())->toBe(2)
        ->and(McpCall::query()->first()->arguments)->toBe(['reason' => 'revoked_token'])
        ->and(collect(mcpAlertTexts())->filter(fn ($text) => str_contains($text, __('telegram.mcp.events.revoked_used', [], 'uz'))))->toHaveCount(1);
});

it('never lets a price API token in, and never lets an MCP token reach the price API (C5)', function () {
    [$admin] = mcpHolder();
    ApiToken::factory()->withPlainText($price = fakeApiTokenValue())->create();

    mcpRequest($price)->assertUnauthorized();

    // An MCP row, even with prices.write forced on it, is refused by the price API.
    ApiToken::factory()->mcp($admin)->withPlainText($mcp = fakeMcpTokenValue('Qq77'))->abilities(['prices.write', 'mcp'])->create();
    postPrices(['items' => [priceItem('A')]], $mcp)->assertUnauthorized();

    // A price_api row whose plain value has the MCP shape is refused by MCP.
    ApiToken::factory()->withPlainText($odd = fakeMcpTokenValue('Ww55'))->create(['user_id' => $admin->id]);
    mcpRequest($odd)->assertUnauthorized();
});

it('does not accept a session login on the MCP endpoint (C6)', function () {
    $admin = staffUser(Role::Admin);
    $admin->forceFill(['mcp_access_granted_at' => now()])->saveQuietly();

    $this->actingAs($admin);

    mcpRequest(null)->assertUnauthorized()->assertHeader('WWW-Authenticate', MCP_WWW_AUTHENTICATE);
});

it('blocks brute force with the shared failure limiter', function () {
    foreach (range(1, AuthenticateApiToken::MAX_FAILURES) as $i) {
        mcpRequest(fakeMcpTokenValue('b'.$i))->assertUnauthorized();
    }

    [$admin] = mcpHolder($plain = fakeMcpTokenValue());

    mcpRequest($plain)->assertStatus(429)->assertHeader('Retry-After');
});

it('refuses a user who is not an active Admin holder with 403 and a denied row (C7)', function (Closure $break, string $reason) {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue());

    $break($admin, $token);

    mcpRequest($plain)->assertForbidden()->assertJsonPath('error', 'forbidden');

    expect(McpCall::query()->sole())
        ->status->toBe(McpCallStatus::Denied)
        ->arguments->toBe(['reason' => $reason]);
})->with([
    // Mass updates skip the observer: EnsureMcpUser still refuses (C8).
    'deactivated by mass update' => [fn (User $admin) => User::query()->whereKey($admin->id)->update(['is_active' => false]), 'inactive'],
    // Spatie role events are off: removing the role is still caught per request.
    'admin role removed' => [fn (User $admin) => $admin->syncRoles([Role::ContentManager->value]), 'not_admin'],
    'access not granted' => [fn (User $admin) => User::query()->whereKey($admin->id)->update(['mcp_access_granted_at' => null]), 'no_access'],
    'second holder' => [fn () => User::factory()->withRole(Role::Admin)->create()->forceFill(['mcp_access_granted_at' => now()])->saveQuietly(), 'not_single_holder'],
]);

it('refuses a token whose user is not the request user', function () {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue());
    $request = request();
    $request->attributes->set(AuthenticateMcpToken::ATTRIBUTE, $token);
    $other = staffUser(Role::Admin);
    $request->setUserResolver(fn () => $other);

    $response = app(EnsureMcpUser::class)->handle($request, fn () => response('passed'));

    expect($response->getStatusCode())->toBe(403)
        ->and(McpCall::query()->sole()->arguments)->toBe(['reason' => 'token_user']);
});

it('enforces the IP allow-list after the hash matched, alerting once per hour (C14)', function () {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue(), ['allowed_ips' => ['10.0.0.0/8', '2001:db8::/32']]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3']);
    mcpRequest($plain)->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    mcpRequest($plain)->assertUnauthorized()->assertHeader('WWW-Authenticate', MCP_WWW_AUTHENTICATE);
    mcpRequest($plain)->assertUnauthorized();

    expect(McpCall::query()->where('status', McpCallStatus::Denied)->pluck('arguments')->all())->toBe([['reason' => 'ip_not_allowed'], ['reason' => 'ip_not_allowed']])
        ->and(collect(mcpAlertTexts())->filter(fn ($text) => str_contains($text, __('telegram.mcp.events.ip_blocked', [], 'uz'))))->toHaveCount(1);
});

it('ignores a spoofed X-Forwarded-For without trusted proxies (C14)', function () {
    config(['app.trusted_proxies' => null]);
    [$admin] = mcpHolder($plain = fakeMcpTokenValue(), ['allowed_ips' => ['10.0.0.1']]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

    mcpRequest($plain, headers: ['X-Forwarded-For' => '10.0.0.1'])->assertUnauthorized();
});

it('alerts on first use, then on a new IP and a new client, once per value (C10)', function () {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue());
    $events = fn (string $event) => collect(mcpAlertTexts())->filter(fn ($text) => str_contains($text, __('telegram.mcp.events.'.$event, [], 'uz')))->count();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_USER_AGENT' => 'node/22.1']);
    mcpRequest($plain)->assertOk();
    mcpRequest($plain)->assertOk();

    expect($events('first_use'))->toBe(1)->and($events('new_ip'))->toBe(0)->and($events('new_client'))->toBe(0);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2', 'HTTP_USER_AGENT' => 'node/22.2']);
    mcpRequest($plain)->assertOk();
    mcpRequest($plain)->assertOk();

    expect($events('new_ip'))->toBe(1)->and($events('new_client'))->toBe(0);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2', 'HTTP_USER_AGENT' => 'python-httpx/0.27']);
    mcpRequest($plain)->assertOk();

    expect($events('new_client'))->toBe(1);

    // Security alerts carry the IP and a short client, never the token or its prefix.
    foreach (mcpAlertTexts() as $text) {
        expect($text)->not->toContain($plain)->not->toContain($token->token_prefix);
    }
});

it('limits each token to 120 requests per minute with a rate_limited row (C17)', function () {
    [$admin] = mcpHolder($plain = fakeMcpTokenValue());

    foreach (range(1, 120) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.intdiv($i, 50)]);
        mcpRequest($plain)->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);
    mcpRequest($plain)->assertStatus(429)->assertHeader('Retry-After');

    expect(McpCall::query()->where('status', McpCallStatus::RateLimited)->count())->toBe(1);
});

it('limits each IP to 60 requests per minute before the token check (C17)', function () {
    foreach (range(1, 60) as $i) {
        mcpRequest(null);
        // Keep the failure limiter out of the way.
        RateLimiter::clear(AuthenticateApiToken::failureKey('127.0.0.1'));
    }

    mcpRequest(null)->assertStatus(429)->assertJsonPath('error', 'rate_limited');
});

it('runs the MCP middleware in the signed-off order (C30)', function () {
    $route = Route::getRoutes()->getByName('mcp.admin');
    $middleware = app(Router::class)->gatherRouteMiddleware($route);

    $position = fn (string $needle) => collect($middleware)->search(fn ($entry) => $entry === $needle);

    expect($route->methods())->toContain('POST')
        ->and($position(ThrottleRequests::class.':mcp-ip'))
        ->toBeLessThan($position(AuthenticateMcpToken::class))
        ->and($position(AuthenticateMcpToken::class))->toBeLessThan($position(EnsureMcpUser::class))
        ->and($position(EnsureMcpUser::class))->toBeLessThan($position(ThrottleRequests::class.':mcp'))
        // No session, cookies or CSRF on the MCP route (C6).
        ->and(collect($middleware)->filter(fn ($entry) => str_contains($entry, 'StartSession') || str_contains($entry, 'VerifyCsrfToken') || str_contains($entry, 'EncryptCookies')))->toBeEmpty();
});

it('registers no OAuth or .well-known routes (C2)', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

    expect($uris->filter(fn ($uri) => str_contains($uri, 'oauth') || str_contains($uri, '.well-known')))->toBeEmpty();
});

it('answers GET and DELETE on the MCP endpoint with 405 and no data', function () {
    [$admin] = mcpHolder($plain = fakeMcpTokenValue());

    $this->get('/mcp/admin')->assertStatus(405);
    $this->delete('/mcp/admin')->assertStatus(405);
    $this->delete('/mcp/admin', [], ['Authorization' => 'Bearer '.$plain])->assertStatus(405)->assertContent('');
});

it('keeps MCP requests out of the traffic analytics and bot hits (C27)', function () {
    expect(config('analytics.exclude_paths'))->toContain('mcp', 'mcp/*');

    [$admin] = mcpHolder($plain = fakeMcpTokenValue());
    $crawler = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot';

    mcpRequest($plain)->assertOk();
    mcpRequest($plain, headers: ['User-Agent' => $crawler])->assertOk();
    mcpRequest(null, headers: ['User-Agent' => $crawler])->assertUnauthorized();

    expect(SiteVisit::query()->count())->toBe(0)->and(BotHit::query()->count())->toBe(0);
});

it('gives every MCP token type mcp and the holder as user', function () {
    [$admin, $token] = mcpHolder();

    expect($token->type)->toBe(ApiTokenType::Mcp)->and($token->user_id)->toBe($admin->id);
});
