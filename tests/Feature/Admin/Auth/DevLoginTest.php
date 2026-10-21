<?php

use App\Actions\Auth\AuthenticateStaff;
use App\Enums\Role;
use App\Livewire\Admin\Auth\Login;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Run the rest of the test as if APP_ENV were $environment, with the dev login flag set.
 */
function devLoginAs(string $environment, bool $flag, bool $debug = true): void
{
    app()['env'] = $environment;
    config(['admin.dev_login' => $flag, 'app.debug' => $debug]);
}

function devLoginAttempt(string $email, string $password): Testable
{
    return Livewire::test(Login::class)
        ->set('email', $email)
        ->set('password', $password)
        ->call('login');
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    RateLimiter::clear(AuthenticateStaff::ipThrottleKey('127.0.0.1'));

    foreach (['staff@poson.test', 'nobody@example.com', AuthenticateStaff::DEV_EMAIL] as $email) {
        RateLimiter::clear(AuthenticateStaff::throttleKey($email, '127.0.0.1'));
    }
});

it('is enabled only in local with the flag', function (string $environment, bool $flag, bool $enabled) {
    devLoginAs($environment, $flag);

    expect(AuthenticateStaff::devLoginEnabled())->toBe($enabled);
})->with([
    'local + flag' => ['local', true, true],
    'local, no flag' => ['local', false, false],
    'production + flag' => ['production', true, false],
    'staging + flag' => ['staging', true, false],
    'testing + flag' => ['testing', true, false],
]);

it('stays off without app.debug', function () {
    devLoginAs('local', true, debug: false);

    expect(AuthenticateStaff::devLoginEnabled())->toBeFalse();
});

it('accepts only loopback and private client IPs', function (string $ip, bool $enabled) {
    devLoginAs('local', true);
    $this->app->instance('request', Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => $ip]));

    expect(AuthenticateStaff::devLoginEnabled())->toBe($enabled);
})->with([
    'loopback v4' => ['127.0.0.1', true],
    'loopback v6' => ['::1', true],
    'private 10/8' => ['10.1.2.3', true],
    'private 172.16/12' => ['172.20.0.5', true],
    'private 192.168/16' => ['192.168.1.10', true],
    'public' => ['8.8.8.8', false],
    'just outside 172.16/12' => ['172.32.0.1', false],
]);

it('rejects a dev login attempt from a public IP', function () {
    devLoginAs('local', true);
    User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']);

    $this->app->instance('request', Request::create('/admin/login', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));

    expect(fn () => app(AuthenticateStaff::class)->handle('staff@poson.test', 'wrong-password', false, '203.0.113.7'))
        ->toThrow(ValidationException::class, __('admin.auth.failed'));

    $this->assertGuest();
});

it('does not seed from a web request when the roles are missing', function () {
    devLoginAs('local', true);
    Spatie\Permission\Models\Role::query()->delete();

    expect(fn () => app(AuthenticateStaff::class)->handle('nobody@example.com', 'x', false, '127.0.0.1'))
        ->toThrow(RuntimeException::class, 'php artisan db:seed');
});

it('signs in an existing staff user with any password', function () {
    devLoginAs('local', true);
    Log::spy();
    $user = User::factory()->withRole(Role::SalesManager)->create(['email' => 'staff@poson.test']);

    devLoginAttempt(' Staff@Poson.test ', 'anything-at-all')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    Log::shouldHaveReceived('warning')->once()->with('Admin dev login used', ['user_id' => $user->id]);
});

it('signs in as the dev admin for an unknown email, creating it once', function () {
    devLoginAs('local', true);

    devLoginAttempt('nobody@example.com', 'x')->assertHasNoErrors();

    $admin = User::where('email', AuthenticateStaff::DEV_EMAIL)->firstOrFail();
    $this->assertAuthenticatedAs($admin);
    expect($admin->hasRole(Role::Admin))->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and(User::where('email', 'nobody@example.com')->exists())->toBeFalse();

    auth()->logout();
    devLoginAttempt('nobody@example.com', 'y')->assertHasNoErrors();

    expect(User::where('email', AuthenticateStaff::DEV_EMAIL)->count())->toBe(1);
    $this->assertAuthenticatedAs($admin);
});

it('uses the dev admin instead of an inactive staff user', function () {
    devLoginAs('local', true);
    User::factory()->withRole(Role::ContentManager)->inactive()->create(['email' => 'staff@poson.test']);

    devLoginAttempt('staff@poson.test', 'x')->assertHasNoErrors();

    expect(auth()->user()->email)->toBe(AuthenticateStaff::DEV_EMAIL);
});

it('still validates the form in dev login', function () {
    devLoginAs('local', true);

    devLoginAttempt('not-an-email', '')
        ->assertHasErrors(['email' => 'email', 'password' => 'required']);

    $this->assertGuest();
});

it('keeps normal sign-in when the dev login is off', function (string $environment, bool $flag) {
    devLoginAs($environment, $flag);
    Log::spy();
    User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']);

    devLoginAttempt('staff@poson.test', 'wrong-password')
        ->assertHasErrors(['email'])
        ->assertSee('E-mail yoki parol notoʻgʻri.');

    devLoginAttempt('nobody@example.com', 'whatever')->assertHasErrors(['email']);

    $this->assertGuest();
    expect(User::where('email', AuthenticateStaff::DEV_EMAIL)->exists())->toBeFalse();
    Log::shouldNotHaveReceived('warning');
})->with([
    'production + flag' => ['production', true],
    'testing + flag' => ['testing', true],
    'local, no flag' => ['local', false],
]);

it('shows the dev login badge only when enabled', function () {
    devLoginAs('local', true);
    $this->get('/admin/login')->assertOk()->assertSee('data-dev-login', false)->assertSeeText('DEV KIRISH: istalgan e-mail va parol');

    devLoginAs('production', true);
    $this->get('/admin/login')->assertOk()->assertDontSee('data-dev-login', false);

    devLoginAs('local', false);
    $this->get('/admin/login')->assertOk()->assertDontSee('data-dev-login', false);
});
