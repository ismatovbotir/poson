<?php

use App\Actions\Auth\AuthenticateStaff;
use App\Enums\Role;
use App\Livewire\Admin\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    RateLimiter::clear(AuthenticateStaff::throttleKey('staff@poson.test', '127.0.0.1'));
    RateLimiter::clear(AuthenticateStaff::ipThrottleKey('127.0.0.1'));
});

it('shows the login page to guests', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSeeLivewire(Login::class)
        ->assertSee('<html lang="uz"', false)
        ->assertSeeText('Boshqaruv paneliga kirish');
});

it('redirects guests from admin pages to the login page', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));
});

it('redirects signed-in staff away from the login page', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->get('/admin/login')
        ->assertRedirect(route('admin.dashboard'));
});

it('signs in staff and redirects to the dashboard', function () {
    $user = User::factory()->withRole(Role::SalesManager)->create(['email' => 'staff@poson.test']);

    Livewire::test(Login::class)
        ->set('email', 'staff@poson.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('regenerates the session id on sign-in', function () {
    User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']);
    $before = session()->getId();

    Livewire::test(Login::class)
        ->set('email', 'staff@poson.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect(session()->getId())->not->toBe($before);
});

it('normalizes the email before signing in', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']);

    Livewire::test(Login::class)
        ->set('email', '  Staff@Poson.TEST ')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertSet('email', 'staff@poson.test');

    $this->assertAuthenticatedAs($user);
});

it('redirects to the intended admin url after sign-in', function () {
    User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']);
    session()->put('url.intended', url('/admin?from=intended'));

    Livewire::test(Login::class)
        ->set('email', 'staff@poson.test')
        ->set('password', 'password')
        ->call('login')
        ->assertRedirect(url('/admin?from=intended'));
});

it('remembers the user when asked', function () {
    $user = User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test', 'remember_token' => null]);

    Livewire::test(Login::class)
        ->set('email', 'staff@poson.test')
        ->set('password', 'password')
        ->set('remember', true)
        ->call('login')
        ->assertHasNoErrors();

    expect($user->fresh()->remember_token)->not->toBeNull()
        ->and(Cookie::hasQueued(Auth::guard('web')->getRecallerName()))->toBeTrue()
        ->and(Auth::guard('web')->getRecallerName())->toStartWith('remember_web_');
});

it('does not queue a remember cookie by default', function () {
    User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']);

    Livewire::test(Login::class)
        ->set('email', 'staff@poson.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    expect(Cookie::hasQueued(Auth::guard('web')->getRecallerName()))->toBeFalse();
});

it('shows the same generic error for any failed sign-in', function (Closure $makeUser, string $password) {
    $makeUser();

    Livewire::test(Login::class)
        ->set('email', 'staff@poson.test')
        ->set('password', $password)
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSee('E-mail yoki parol notoʻgʻri.')
        ->assertSet('password', '');

    $this->assertGuest();
})->with([
    'wrong password' => [fn () => User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']), 'wrong-password'],
    'unknown email' => [fn () => null, 'password'],
    'inactive user' => [fn () => User::factory()->withRole(Role::Admin)->inactive()->create(['email' => 'staff@poson.test']), 'password'],
    'user without a staff role' => [fn () => User::factory()->create(['email' => 'staff@poson.test']), 'password'],
]);

it('validates the input', function () {
    Livewire::test(Login::class)
        ->set('email', 'not-an-email')
        ->set('password', '')
        ->call('login')
        ->assertHasErrors(['email' => 'email', 'password' => 'required']);
});

it('throttles the sixth attempt within a minute', function () {
    User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']);

    foreach (range(1, AuthenticateStaff::MAX_ATTEMPTS) as $attempt) {
        Livewire::test(Login::class)
            ->set('email', 'staff@poson.test')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertSee('E-mail yoki parol notoʻgʻri.');
    }

    // Even the right password is refused while throttled.
    Livewire::test(Login::class)
        ->set('email', 'staff@poson.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors(['email'])
        ->assertSee('Kirish urinishlari juda koʻp.');

    $this->assertGuest();
});

it('throttles an ip after 20 failed attempts across emails', function () {
    User::factory()->withRole(Role::Admin)->create(['email' => 'staff@poson.test']);

    foreach (range(1, AuthenticateStaff::MAX_ATTEMPTS_PER_IP) as $attempt) {
        Livewire::test(Login::class)
            ->set('email', "guess{$attempt}@poson.test")
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertSee('E-mail yoki parol notoʻgʻri.');
    }

    Livewire::test(Login::class)
        ->set('email', 'staff@poson.test')
        ->set('password', 'password')
        ->call('login')
        ->assertSee('Kirish urinishlari juda koʻp.');

    $this->assertGuest();

    foreach (range(1, AuthenticateStaff::MAX_ATTEMPTS_PER_IP) as $attempt) {
        RateLimiter::clear(AuthenticateStaff::throttleKey("guess{$attempt}@poson.test", '127.0.0.1'));
    }
});

it('keys the rate limit by email and ip', function () {
    expect(AuthenticateStaff::throttleKey(' Staff@Poson.test ', '10.0.0.1'))
        ->toBe(AuthenticateStaff::throttleKey('staff@poson.test', '10.0.0.1'))
        ->not->toBe(AuthenticateStaff::throttleKey('staff@poson.test', '10.0.0.2'));
});

it('signs out with a POST, invalidating the session', function () {
    $user = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($user)->withSession(['marker' => 'kept?']);

    $this->post('/admin/logout')
        ->assertRedirect(route('admin.login'))
        ->assertSessionMissing('marker');

    $this->assertGuest();
});

it('does not sign out with a GET', function () {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create())
        ->get('/admin/logout')
        ->assertMethodNotAllowed();
});

it('has no registration route anywhere', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

    expect($uris->filter(fn (string $uri) => str_contains($uri, 'register')))->toBeEmpty()
        ->and(Route::has('register'))->toBeFalse()
        ->and(Route::has('admin.register'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->get('/admin/register')->assertNotFound();
});
