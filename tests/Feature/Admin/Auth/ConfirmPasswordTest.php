<?php

use App\Enums\Role;
use App\Http\Middleware\EnsureStaff;
use App\Livewire\Admin\Auth\ConfirmPassword;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function passwordUser(): User
{
    $user = staffUser();
    $user->forceFill(['password' => Hash::make('correct-horse-battery')])->save();

    return $user;
}

function confirmWith(User $user, string $password): Testable
{
    return Livewire::actingAs($user)->test(ConfirmPassword::class)
        ->set('password', $password)
        ->call('confirm');
}

it('renders for signed-in staff only', function () {
    $this->get(route('admin.confirm-password'))->assertRedirect(route('admin.login'));

    $this->actingAs(staffUser(Role::SalesManager))
        ->get(route('admin.confirm-password'))
        ->assertOk()
        ->assertSeeLivewire(ConfirmPassword::class);
});

it('confirms the right password and returns to the intended admin page', function () {
    session()->put('url.intended', route('admin.settings.integrations'));

    confirmWith(passwordUser(), 'correct-horse-battery')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.integrations'))
        ->assertSet('password', '');

    expect(PasswordConfirmation::isRecent())->toBeTrue();
});

it('falls back to the dashboard for an intended URL outside the admin', function (string $url) {
    session()->put('url.intended', $url);

    confirmWith(passwordUser(), 'correct-horse-battery')->assertRedirect(route('admin.dashboard'));
})->with([
    'site page' => ['http://localhost/uz'],
    'other host' => ['https://evil.example/admin/settings'],
    'admin look-alike' => ['http://localhost/administrator'],
]);

it('rejects a wrong password and does not confirm', function () {
    confirmWith(passwordUser(), 'wrong-password')
        ->assertHasErrors('password')
        ->assertNoRedirect()
        ->assertSet('password', '');

    expect(PasswordConfirmation::isRecent())->toBeFalse();
});

it('limits wrong attempts', function () {
    $user = passwordUser();

    foreach (range(1, ConfirmPassword::MAX_ATTEMPTS) as $attempt) {
        confirmWith($user, 'wrong-password')->assertHasErrors('password');
    }

    confirmWith($user, 'correct-horse-battery')->assertHasErrors('password')->assertNoRedirect();

    expect(PasswordConfirmation::isRecent())->toBeFalse();
});

it('limits wrong attempts per hour too', function () {
    $user = passwordUser();

    foreach (range(1, ConfirmPassword::MAX_ATTEMPTS_PER_HOUR) as $attempt) {
        RateLimiter::clear(ConfirmPassword::minuteKey($user));
        Cache::forget(ConfirmPassword::failuresKey($user));
        confirmWith($user, 'wrong-password')->assertHasErrors('password');
    }

    RateLimiter::clear(ConfirmPassword::minuteKey($user));

    confirmWith($user, 'correct-horse-battery')
        ->assertHasErrors(['password' => __('admin.auth.throttle', ['seconds' => RateLimiter::availableIn(ConfirmPassword::hourKey($user))])])
        ->assertNoRedirect();

    expect(PasswordConfirmation::isRecent())->toBeFalse();
});

it('logs every failure with the user and IP', function () {
    $user = passwordUser();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });

    confirmWith($user, 'wrong-SECRETPW')->assertHasErrors('password');

    $failure = collect($logged)->first(fn (MessageLogged $event): bool => $event->message === 'Admin password confirmation failed');

    expect($failure)->not->toBeNull()
        ->and($failure->level)->toBe('warning')
        ->and($failure->context['user_id'])->toBe($user->id)
        ->and($failure->context['ip'])->toBe('127.0.0.1')
        ->and(json_encode($failure->context))->not->toContain('SECRETPW');
});

it('signs the user out after 10 failures in a row', function () {
    $user = passwordUser();

    foreach (range(1, ConfirmPassword::MAX_CONSECUTIVE_FAILURES - 1) as $attempt) {
        RateLimiter::clear(ConfirmPassword::minuteKey($user));
        confirmWith($user, 'wrong-password')->assertHasErrors('password');
    }

    $this->assertAuthenticatedAs($user);
    RateLimiter::clear(ConfirmPassword::minuteKey($user));
    session()->put('marker', 'kept');

    confirmWith($user, 'wrong-password')->assertRedirect(route('admin.login'));

    $this->assertGuest();
    expect(session('marker'))->toBeNull()
        ->and(session(EnsureStaff::NOTICE_KEY))->toBe(__('admin.confirm_password.logged_out'));
});

it('resets the consecutive failures after a success', function () {
    $user = passwordUser();

    foreach (range(1, 4) as $attempt) {
        confirmWith($user, 'wrong-password');
    }

    confirmWith($user, 'correct-horse-battery')->assertHasNoErrors();

    expect(Cache::get(ConfirmPassword::failuresKey($user)))->toBeNull();
});

it('rotates the session ID on success', function () {
    $user = passwordUser();
    session()->put('marker', 'kept');
    $before = session()->getId();

    confirmWith($user, 'correct-horse-battery')->assertHasNoErrors();

    expect(session()->getId())->not->toBe($before)
        ->and(session('marker'))->toBe('kept');
});

it('expires after 15 minutes', function () {
    PasswordConfirmation::confirm();

    $this->travel(14)->minutes();
    expect(PasswordConfirmation::isRecent())->toBeTrue();

    $this->travel(1)->minutes();
    expect(PasswordConfirmation::isRecent())->toBeFalse()
        ->and(PasswordConfirmation::timeout())->toBe(900);
});

it('never skips the password check outside local, even with the dev login flag', function (string $environment) {
    app()['env'] = $environment;
    config(['admin.dev_login' => true, 'app.debug' => true]);

    confirmWith(passwordUser(), 'anything')->assertHasErrors('password');

    expect(PasswordConfirmation::isRecent())->toBeFalse();
})->with(['production', 'staging', 'testing']);

it('accepts any password with the local dev login', function () {
    app()['env'] = 'local';
    config(['admin.dev_login' => true, 'app.debug' => true]);

    confirmWith(passwordUser(), 'anything')->assertHasNoErrors();

    expect(PasswordConfirmation::isRecent())->toBeTrue();
});
