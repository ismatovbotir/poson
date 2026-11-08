<?php

use App\Actions\Auth\AuthenticateStaff;
use App\Actions\Users\WriteStaffCredentials;
use App\Enums\AuditAction;
use App\Enums\McpRevokeReason;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureStaff;
use App\Livewire\Admin\Auth\Login;
use App\Models\Lead;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\StaffSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Validated;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
| Plan 24 A1. Sessions are real (not actingAs, which holds a user in memory only).
| Guards are forgotten before a request, so the user is re-read from the database
| and the session, as on a real request. One /livewire/update per test.
*/

function staffSessionReloadAuth(): void
{
    auth()->forgetGuards();
}

/**
 * A real session for the user (stamped by the Login listener), re-read on the next request.
 */
function staffSessionStart(User $user): void
{
    Auth::guard('web')->login($user);
    staffSessionReloadAuth();
}

function staffSessionSignIn(User $user, bool $remember = false): void
{
    app(AuthenticateStaff::class)->handle($user->email, 'password', $remember, '127.0.0.1');
    session()->regenerate();
}

/**
 * Sign in with "remember me" and return the real cookie value, without the session.
 */
function staffSessionRememberCookie(User $user): string
{
    staffSessionSignIn($user, remember: true);

    $name = Auth::guard('web')->getRecallerName();
    $value = Cookie::queued($name)->getValue();
    Cookie::unqueue($name);

    session()->flush();
    staffSessionReloadAuth();

    return $value;
}

function staffSessionBump(User $user): void
{
    DB::table('users')->where('id', $user->id)->update(['session_epoch' => DB::raw('session_epoch + 1'), 'remember_token' => Str::random(60)]);
}

function staffSessionSnapshot(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);

        if (data_get(json_decode($snapshot, true), 'memo.name') === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No snapshot for {$component}: ".implode(', ', array_map(fn (string $encoded): string => (string) data_get(json_decode(html_entity_decode($encoded, ENT_QUOTES), true), 'memo.name'), $matches[1])));
}

/**
 * A real Livewire update request, with the headers a browser sends (it does not ask for JSON).
 */
function staffSessionPoll(string $snapshot): TestResponse
{
    return test()->json('POST', app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]],
            'updates' => [],
        ]],
    ], ['X-Livewire' => '1', 'Accept' => '*/*']);
}

/**
 * /admin/leads rendered for a signed-in Admin, with one lead on the page.
 *
 * @return array{0: User, 1: string}
 */
function staffSessionOpenLeads(string $customerName = 'Zarnigor Maxfiyova'): array
{
    $user = staffUser(Role::Admin);
    Lead::factory()->create(['name' => $customerName]);

    staffSessionStart($user);

    $html = test()->get(route('admin.leads.index'))->assertOk()->assertSee($customerName)->getContent();

    return [$user, staffSessionSnapshot($html, 'admin.leads')];
}

beforeEach(function () {
    RateLimiter::clear(AuthenticateStaff::ipThrottleKey('127.0.0.1'));
});

it('stamps the epoch and last login on a password sign-in, without model events', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['updated_at' => now()->subDay()]);
    DB::table('users')->where('id', $user->id)->update(['session_epoch' => 7]);
    $updatedAt = $user->fresh()->updated_at;

    staffSessionSignIn($user->fresh());

    expect(session(StaffSession::EPOCH_KEY))->toBe(7)
        ->and($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();

    staffSessionReloadAuth();
    $this->get('/admin')->assertOk();
});

it('leaves the session stale when a bump lands between the credential check and the stamp', function () {
    $user = User::factory()->withRole(Role::Admin)->create();

    Event::listen(Validated::class, fn () => staffSessionBump($user));

    staffSessionSignIn($user);

    expect(session(StaffSession::EPOCH_KEY))->toBe(0)
        ->and($user->fresh()->session_epoch)->toBe(1);

    staffSessionReloadAuth();
    $this->get('/admin')->assertRedirect(route('admin.login'));

    $this->assertGuest();
});

it('treats a session without a stamp as epoch 0', function () {
    $user = User::factory()->withRole(Role::Admin)->create();

    staffSessionStart($user);
    session()->forget(StaffSession::EPOCH_KEY);

    $this->get('/admin')->assertOk();

    staffSessionBump($user);
    staffSessionReloadAuth();

    $this->get('/admin')->assertRedirect(route('admin.login'));
    $this->assertGuest();
});

it('refuses a bumped session on a real Livewire update, with no customer data', function () {
    [$user, $snapshot] = staffSessionOpenLeads('Zarnigor Maxfiyova');
    $token = $user->fresh()->remember_token;

    DB::table('users')->where('id', $user->id)->update(['session_epoch' => 1]);
    staffSessionReloadAuth();
    expect(auth()->user()?->id)->toBe($user->id);

    staffSessionPoll($snapshot)->assertForbidden()->assertDontSee('Zarnigor Maxfiyova');

    $this->assertGuest();
    expect($user->fresh()->remember_token)->toBe($token);
});

it('gives a logged-out poll a clean refusal, with no customer data', function () {
    [, $snapshot] = staffSessionOpenLeads('Zarnigor Maxfiyova');

    Auth::guard('web')->logout();
    session()->flush();
    staffSessionReloadAuth();

    $response = staffSessionPoll($snapshot);

    $response->assertForbidden()->assertDontSee('Zarnigor Maxfiyova')->assertDontSee('wire:snapshot', false);
    expect($response->headers->get('Location'))->toBeNull();
});

it('redirects a bumped session on a full page to the login page with the notice', function () {
    $user = User::factory()->withRole(Role::Admin)->create();
    staffSessionStart($user);
    session()->put('marker', 'old session');

    staffSessionBump($user);
    staffSessionReloadAuth();

    $this->get('/admin')
        ->assertRedirect(route('admin.login'))
        ->assertSessionMissing('marker')
        ->assertSessionHas(EnsureStaff::NOTICE_KEY, __('admin.auth.session_ended'));

    $this->assertGuest();
});

it('signs in from a remember cookie, firing Login: stamp and last login', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['remember_token' => null]);
    $cookie = staffSessionRememberCookie($user);

    DB::table('users')->where('id', $user->id)->update(['last_login_at' => null, 'session_epoch' => 3]);
    expect(session(StaffSession::EPOCH_KEY))->toBeNull();

    $this->withCookie(Auth::guard('web')->getRecallerName(), $cookie)->get('/admin')->assertOk();

    expect(session(StaffSession::EPOCH_KEY))->toBe(3)
        ->and($user->fresh()->last_login_at)->not->toBeNull();
});

it('rejects an old remember cookie after the credentials were written', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['remember_token' => null]);
    $cookie = staffSessionRememberCookie($user);

    staffSessionBump($user);

    $this->withCookie(Auth::guard('web')->getRecallerName(), $cookie)
        ->get('/admin')
        ->assertRedirect(route('admin.login'));

    $this->assertGuest();
});

it('keeps a valid remember cookie elsewhere working when a stale tab is refused', function () {
    $user = User::factory()->withRole(Role::Admin)->create();
    staffSessionBump($user);

    // This device signs in after the bump and holds the current cookie.
    $cookie = staffSessionRememberCookie($user->fresh());
    $token = $user->fresh()->remember_token;

    // The stale tab: same user, older epoch.
    Auth::guard('web')->loginUsingId($user->id);
    session()->put(StaffSession::EPOCH_KEY, 0);
    staffSessionReloadAuth();

    $this->get('/admin')->assertRedirect(route('admin.login'));
    $this->assertGuest();
    expect($user->fresh()->remember_token)->toBe($token);

    session()->flush();
    staffSessionReloadAuth();

    $this->withCookie(Auth::guard('web')->getRecallerName(), $cookie)->get('/admin')->assertOk();
    $this->assertAuthenticatedAs($user->fresh());
});

it('does not rotate the remember token when a stale tab presses logout', function () {
    $user = User::factory()->withRole(Role::Admin)->create();
    staffSessionStart($user);

    staffSessionBump($user);
    $token = $user->fresh()->remember_token;
    staffSessionReloadAuth();

    $this->post('/admin/logout')->assertRedirect(route('admin.login'));

    $this->assertGuest();
    expect($user->fresh()->remember_token)->toBe($token);
});

it('rotates the remember token on an ordinary logout', function () {
    $user = User::factory()->withRole(Role::Admin)->create();
    $token = $user->remember_token;

    $this->actingAs($user)->post('/admin/logout')->assertRedirect(route('admin.login'));

    expect($user->fresh()->remember_token)->not->toBe($token);
});

it('does not save a locale for a stale session', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['locale' => 'uz']);
    staffSessionStart($user);

    staffSessionBump($user);
    staffSessionReloadAuth();

    $this->post('/admin/locale', ['locale' => 'ru']);

    expect($user->fresh()->locale)->toBe('uz');
});

it('fails an expired temporary password with the generic error and counts the attempt', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['email' => 'temp@poson.test']);
    $user->forceFill(['temp_password_expires_at' => now()->subMinute()])->save();
    RateLimiter::clear(AuthenticateStaff::throttleKey('temp@poson.test', '127.0.0.1'));

    Livewire::test(Login::class)
        ->set('email', 'temp@poson.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSee(__('admin.auth.failed'));

    $this->assertGuest();
    expect(RateLimiter::attempts(AuthenticateStaff::throttleKey('temp@poson.test', '127.0.0.1')))->toBe(1);
});

it('signs in with a valid temporary password, then forces the change', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['email' => 'temp2@poson.test']);
    $user->forceFill(['temp_password_expires_at' => now()->addHour()])->save();
    RateLimiter::clear(AuthenticateStaff::throttleKey('temp2@poson.test', '127.0.0.1'));

    staffSessionSignIn($user);
    staffSessionReloadAuth();

    $this->get('/admin')->assertRedirect(route('admin.account.password'));
    $this->assertAuthenticatedAs($user->fresh());
});

it('signs out a live session when the temporary password expires', function () {
    $user = User::factory()->withRole(Role::Admin)->create();
    $user->forceFill(['temp_password_expires_at' => now()->addMinute()])->save();
    $this->actingAs($user);
    $token = $user->fresh()->remember_token;

    $this->travel(2)->minutes();

    $this->get(route('admin.account.password'))
        ->assertRedirect(route('admin.login'))
        ->assertSessionHas(EnsureStaff::NOTICE_KEY, __('admin.auth.session_ended'));

    $this->assertGuest();
    expect($user->fresh()->remember_token)->toBe($token);
});

it('refuses a remember-cookie session with an expired temporary password', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['remember_token' => null]);
    $cookie = staffSessionRememberCookie($user);

    DB::table('users')->where('id', $user->id)->update(['temp_password_expires_at' => now()->subMinute(), 'last_login_at' => null]);

    $this->withCookie(Auth::guard('web')->getRecallerName(), $cookie)
        ->get('/admin')
        ->assertRedirect(route('admin.login'));

    $this->assertGuest();
    expect($user->fresh()->last_login_at)->toBeNull();
});

it('sends a remember-cookie session with a valid temporary password to the change screen', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['remember_token' => null]);
    $cookie = staffSessionRememberCookie($user);

    DB::table('users')->where('id', $user->id)->update(['temp_password_expires_at' => now()->addHour()]);

    $this->withCookie(Auth::guard('web')->getRecallerName(), $cookie)
        ->get('/admin')
        ->assertRedirect(route('admin.account.password'));
});

it('blocks a forced user on every admin page except the password screen and logout', function () {
    $user = staffUser(Role::Admin);
    $user->forceFill(['temp_password_expires_at' => now()->addHour()])->save();
    $this->actingAs($user);

    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.account.password'));
    $this->get(route('admin.leads.index'))->assertRedirect(route('admin.account.password'));
    $this->get(route('admin.confirm-password'))->assertRedirect(route('admin.account.password'));
    $this->getJson(route('admin.leads.index'))->assertForbidden();

    $this->assertAuthenticatedAs($user);

    $this->get(route('admin.account.password'))->assertOk()->assertSee('data-forced-change', false);

    $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
    $this->assertGuest();
});

it('refuses a forced user on a real Livewire update from a /leads snapshot, and keeps them signed in', function () {
    [$user, $snapshot] = staffSessionOpenLeads('Zarnigor Maxfiyova');

    DB::table('users')->where('id', $user->id)->update(['temp_password_expires_at' => now()->addHour()]);
    staffSessionReloadAuth();
    expect(auth()->user()?->id)->toBe($user->id);

    staffSessionPoll($snapshot)->assertForbidden()->assertDontSee('Zarnigor Maxfiyova');

    $this->assertAuthenticatedAs($user->fresh());
});

it('allows a normal user on a real Livewire update from a /leads snapshot', function () {
    [$user, $snapshot] = staffSessionOpenLeads('Zarnigor Maxfiyova');

    staffSessionReloadAuth();

    staffSessionPoll($snapshot)->assertOk();

    $this->assertAuthenticatedAs($user->fresh());
});

it('puts every staff route behind the forced change except the password screen', function () {
    $unguarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('admin', $route->gatherMiddleware(), true))
        ->reject(fn ($route): bool => in_array('admin.password-changed', $route->gatherMiddleware(), true))
        ->map(fn ($route): ?string => $route->getName())
        ->values()->all();

    expect($unguarded)->toBe(['admin.account.password'])
        ->and(app(EnsurePasswordChanged::class))->toBeInstanceOf(EnsurePasswordChanged::class);
});

it('ends sessions through the one write path without touching the password', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['remember_token' => null]);
    $cookie = staffSessionRememberCookie($user);
    $user = $user->fresh();
    $password = $user->password;

    $saved = app(WriteStaffCredentials::class)->endSessions($user, 'user.'.$user->id.'.role', AuditAction::Updated, $user, '127.0.0.1');

    expect($saved)->session_epoch->toBe(1)
        ->and($saved->remember_token)->not->toBe($user->remember_token)
        ->and($saved->password)->toBe($password)
        ->and($saved->password_changed_at)->toBeNull()
        ->and($saved->is_active)->toBeTrue();

    expect(SettingsAudit::query()->where('key', 'user.'.$user->id.'.role')->where('area', SettingsArea::Users->value)->count())->toBe(1);

    $this->withCookie(Auth::guard('web')->getRecallerName(), $cookie)->get('/admin')->assertRedirect(route('admin.login'));
    $this->assertGuest();
});

it('saves a deactivation in the same write, so the observer revokes MCP access', function () {
    [$admin, $token] = mcpHolder();

    app(WriteStaffCredentials::class)->endSessions($admin, 'user.'.$admin->id.'.active', AuditAction::Disabled, null, null, isActive: false);

    expect($admin->fresh())->is_active->toBeFalse()->session_epoch->toBe(1)->mcp_access_granted_at->toBeNull()
        ->and($token->fresh()->revoked_reason)->toBe(McpRevokeReason::Deactivated);
});

it('writes nothing when the guard under the lock refuses ending sessions', function () {
    $user = User::factory()->withRole(Role::Admin)->create();
    $token = $user->remember_token;

    expect(app(WriteStaffCredentials::class)->endSessions($user, 'user.'.$user->id.'.role', AuditAction::Updated, null, null, fn (User $locked): bool => false))->toBeNull()
        ->and($user->fresh())->session_epoch->toBe(0)->remember_token->toBe($token)
        ->and(SettingsAudit::query()->count())->toBe(0);
});

it('hides the new columns and does not let fill() write them', function () {
    $user = User::factory()->make();

    expect(fn () => $user->fill(['session_epoch' => 9]))->toThrow(MassAssignmentException::class);

    expect($user->session_epoch)->toBe(0)
        ->and($user->getFillable())->not->toContain('session_epoch', 'temp_password_expires_at', 'password_changed_at', 'last_login_at')
        ->and($user->getHidden())->toContain('session_epoch', 'temp_password_expires_at', 'password_changed_at', 'last_login_at');

    $created = User::factory()->create();
    $created->forceFill(['last_login_at' => now(), 'password_changed_at' => now()])->save();

    expect(array_keys($created->fresh()->toArray()))
        ->not->toContain('session_epoch', 'temp_password_expires_at', 'password_changed_at', 'last_login_at', 'password', 'remember_token');
});

it('keeps staff account audit rows off the Integrations list', function () {
    $this->actingAs(staffUser(Role::Admin));
    SettingsAudit::record(SettingsArea::Users, 'user.991.password', AuditAction::PasswordReset, null, null);
    SettingsAudit::record(SettingsArea::Integrations, 'integrations.control', AuditAction::Updated, null, null);

    $this->get(route('admin.settings.integrations'))
        ->assertOk()
        ->assertSee('integrations.control')
        ->assertDontSee('user.991.password');
});

it('labels the new audit area and actions in all three languages and restricts the area', function (string $locale) {
    app()->setLocale($locale);

    expect(SettingsArea::Users->label())->not->toContain('enums.')
        ->and(AuditAction::PasswordChanged->label())->not->toContain('enums.')
        ->and(AuditAction::PasswordReset->label())->not->toContain('enums.')
        ->and(SettingsArea::Users->isRestricted())->toBeTrue()
        ->and(SettingsArea::Users->group())->toBe('integrations');
})->with(['uz', 'ru', 'en']);

it('answers a stale admin Livewire poll with 403, but leaves public-site Livewire alone', function (string $referer, bool $isAdmin) {
    $update = app('livewire')->getUpdateUri();
    $request = Request::create($update, 'POST', server: ['HTTP_REFERER' => url($referer), 'HTTP_X_LIVEWIRE' => '1']);
    app()->instance('request', $request);

    $rendered = app(ExceptionHandler::class)->render($request, new AuthenticationException);

    expect($rendered->getStatusCode())->toBe($isAdmin ? 403 : 302);
})->with([
    'admin page' => ['/admin/orders', true],
    'public page' => ['/uz/cart', false],
]);
