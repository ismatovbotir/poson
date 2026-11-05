<?php

use App\Actions\Users\ChangeOwnPassword;
use App\Enums\AuditAction;
use App\Enums\McpRevokeReason;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Http\Middleware\EnsureStaff;
use App\Livewire\Admin\Account\Password;
use App\Livewire\Admin\Auth\ConfirmPassword;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Admin\StaffSession;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Plan 24 A1, P24-5 and P24-6. Permission and session behaviour goes through a
| real /livewire/update request (one per test); rule details through Livewire::test().
*/

const STAFF_PW_CURRENT = 'Old-Granite-Window-47';

const STAFF_PW_NEW = 'Tall-Copper-Lantern-82';

function passwordScreenUser(?Role $role = Role::Admin, array $attributes = []): User
{
    $user = staffUser($role);
    $user->forceFill(['password' => Hash::make(STAFF_PW_CURRENT), 'name' => 'Aziz Karimov', 'email' => 'aziz.karimov@poson.test', ...$attributes])->save();

    return $user;
}

function passwordScreenSubmit(User $user, string $current = STAFF_PW_CURRENT, string $new = STAFF_PW_NEW, ?string $repeat = null): Testable
{
    return Livewire::actingAs($user)->test(Password::class)
        ->set('currentPassword', $current)
        ->set('newPassword', $new)
        ->set('newPasswordConfirmation', $repeat ?? $new)
        ->call('save');
}

/**
 * Render the screen and post a real save, as a browser does.
 */
function passwordScreenRealSave(User $user, string $current = STAFF_PW_CURRENT, string $new = STAFF_PW_NEW): TestResponse
{
    Auth::guard('web')->login($user);
    auth()->forgetGuards();

    $html = test()->get(route('admin.account.password'))->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);

    return test()->withHeaders(['X-Livewire' => '1'])
        ->postJson(app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => html_entity_decode($matches[1] ?? '', ENT_QUOTES),
                'calls' => [['method' => 'save', 'params' => [], 'metadata' => []]],
                'updates' => ['currentPassword' => $current, 'newPassword' => $new, 'newPasswordConfirmation' => $new],
            ]],
        ]);
}

it('opens for every staff role on the guest layout, with no admin menu', function (Role $role) {
    $this->actingAs(passwordScreenUser($role))
        ->get(route('admin.account.password'))
        ->assertOk()
        ->assertSeeLivewire(Password::class)
        ->assertDontSee('id="admin-sidebar"', false)
        ->assertDontSee('data-forced-change', false)
        ->assertSee(route('admin.logout'), false);
})->with(Role::cases());

it('sends guests to the login page', function () {
    $this->get(route('admin.account.password'))->assertRedirect(route('admin.login'));
});

it('links to the screen from the user menu', function () {
    $this->actingAs(staffUser())->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('admin.account.password'), false);
});

it('lets a forced user change the password through a real Livewire update', function () {
    $user = passwordScreenUser();
    $user->forceFill(['temp_password_expires_at' => now()->addHour()])->save();
    $tokenBefore = $user->fresh()->remember_token;
    $sessionBefore = session()->getId();
    session()->put(PasswordConfirmation::SESSION_KEY, now()->unix());

    $response = passwordScreenRealSave($user);

    $response->assertOk();
    expect($response->getContent())
        ->toContain(str_replace('/', '\/', route('admin.dashboard')))
        ->not->toContain(STAFF_PW_NEW)
        ->not->toContain(STAFF_PW_CURRENT);

    $fresh = $user->fresh();

    expect(Hash::check(STAFF_PW_NEW, $fresh->password))->toBeTrue()
        ->and($fresh->temp_password_expires_at)->toBeNull()
        ->and($fresh->session_epoch)->toBe(1)
        ->and($fresh->remember_token)->not->toBe($tokenBefore)
        ->and($fresh->password_changed_at)->not->toBeNull()
        ->and(session(StaffSession::EPOCH_KEY))->toBe(1)
        ->and(session(PasswordConfirmation::SESSION_KEY))->toBeNull()
        ->and(session()->getId())->not->toBe($sessionBefore);

    expect(SettingsAudit::query()->where('area', SettingsArea::Users->value)->sole())
        ->key->toBe('user.'.$user->id.'.password')
        ->action->toBe(AuditAction::PasswordChanged)
        ->user_id->toBe($user->id);

    // This session goes on, re-read from the database as on the next real request.
    auth()->forgetGuards();
    $this->get(route('admin.dashboard'))->assertOk();
    $this->assertAuthenticatedAs($fresh);
});

it('ends other sessions on an own change', function () {
    $user = passwordScreenUser();
    Auth::guard('web')->login($user);

    passwordScreenSubmit($user)->assertHasNoErrors();

    auth()->forgetGuards();
    $this->get(route('admin.dashboard'))->assertOk();

    // Any other session still carries the old epoch.
    session()->put(StaffSession::EPOCH_KEY, 0);
    auth()->forgetGuards();

    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    $this->assertGuest();
});

it('revokes the user\'s own MCP tokens, and warns first', function () {
    [$admin, $token] = mcpHolder();
    $admin->forceFill(['password' => Hash::make(STAFF_PW_CURRENT), 'name' => 'Aziz Karimov', 'email' => 'aziz.karimov@poson.test'])->saveQuietly();
    $token->refresh();
    expect($token->revoked_at)->toBeNull();

    $this->actingAs($admin)->get(route('admin.account.password'))->assertOk()->assertSee('data-mcp-warning', false);

    passwordScreenSubmit($admin)->assertHasNoErrors();

    expect($token->fresh())
        ->revoked_at->not->toBeNull()
        ->revoked_reason->toBe(McpRevokeReason::PasswordChanged);
    expect($admin->fresh()->hasMcpAccess())->toBeFalse();
});

it('shows no MCP warning to a user without MCP access', function () {
    $this->actingAs(passwordScreenUser())->get(route('admin.account.password'))->assertDontSee('data-mcp-warning', false);
});

it('refuses a wrong current password and clears every field', function () {
    $user = passwordScreenUser();

    passwordScreenSubmit($user, 'Wrong-Granite-Window-99')
        ->assertHasErrors(['currentPassword'])
        ->assertHasNoErrors(['newPassword'])
        ->assertNoRedirect()
        ->assertSet('currentPassword', '')
        ->assertSet('newPassword', '')
        ->assertSet('newPasswordConfirmation', '');

    expect(Hash::check(STAFF_PW_CURRENT, $user->fresh()->password))->toBeTrue();
});

it('clears every field after a rule failure and after success', function () {
    $user = passwordScreenUser();

    passwordScreenSubmit($user, STAFF_PW_CURRENT, 'short')
        ->assertHasErrors(['newPassword'])
        ->assertSet('currentPassword', '')
        ->assertSet('newPassword', '')
        ->assertSet('newPasswordConfirmation', '');

    passwordScreenSubmit($user)
        ->assertHasNoErrors()
        ->assertSet('currentPassword', '')
        ->assertSet('newPassword', '')
        ->assertSet('newPasswordConfirmation', '');
});

it('never reveals "same as current" before the current password is verified', function () {
    $user = passwordScreenUser();

    $component = passwordScreenSubmit($user, 'Wrong-Granite-Window-99', STAFF_PW_CURRENT)
        ->assertHasErrors(['currentPassword'])
        ->assertHasNoErrors(['newPassword']);

    $component->assertDontSee(__('staff.password_rule.same_as_current'));
    expect(RateLimiter::attempts(ConfirmPassword::minuteKey($user)))->toBe(1);
});

it('refuses a new password equal to the current one', function () {
    passwordScreenSubmit(passwordScreenUser(), STAFF_PW_CURRENT, STAFF_PW_CURRENT)
        ->assertHasErrors(['newPassword'])
        ->assertSee(__('staff.password_rule.same_as_current'));
});

it('refuses a repeat that does not match', function () {
    passwordScreenSubmit(passwordScreenUser(), STAFF_PW_CURRENT, STAFF_PW_NEW, 'Another-Copper-Lantern-11')
        ->assertHasErrors(['newPasswordConfirmation'])
        ->assertNoRedirect();
});

it('shares the attempt limits with the confirm-password screen', function () {
    $user = passwordScreenUser();

    foreach (range(1, ConfirmPassword::MAX_ATTEMPTS) as $attempt) {
        passwordScreenSubmit($user, 'Wrong-Granite-Window-99')->assertHasErrors(['currentPassword']);
    }

    Livewire::actingAs($user)->test(ConfirmPassword::class)
        ->set('password', STAFF_PW_CURRENT)
        ->call('confirm')
        ->assertHasErrors(['password' => __('admin.auth.throttle', ['seconds' => RateLimiter::availableIn(ConfirmPassword::minuteKey($user))])]);

    passwordScreenSubmit($user)->assertHasErrors(['currentPassword'])->assertNoRedirect();
    expect(Hash::check(STAFF_PW_CURRENT, $user->fresh()->password))->toBeTrue();
});

it('limits attempts per hour across both screens', function () {
    $user = passwordScreenUser();

    foreach (range(1, ConfirmPassword::MAX_ATTEMPTS_PER_HOUR) as $attempt) {
        RateLimiter::clear(ConfirmPassword::minuteKey($user));
        Cache::forget(ConfirmPassword::failuresKey($user));
        passwordScreenSubmit($user, 'Wrong-Granite-Window-99')->assertHasErrors(['currentPassword']);
    }

    RateLimiter::clear(ConfirmPassword::minuteKey($user));

    passwordScreenSubmit($user)->assertHasErrors(['currentPassword'])->assertNoRedirect();
    expect(Hash::check(STAFF_PW_CURRENT, $user->fresh()->password))->toBeTrue();
});

it('signs out after 10 failures in a row, counted across both screens', function () {
    $user = passwordScreenUser();

    foreach (range(1, ConfirmPassword::MAX_CONSECUTIVE_FAILURES - 1) as $attempt) {
        RateLimiter::clear(ConfirmPassword::minuteKey($user));
        Livewire::actingAs($user)->test(ConfirmPassword::class)->set('password', 'wrong')->call('confirm')->assertHasErrors('password');
    }

    RateLimiter::clear(ConfirmPassword::minuteKey($user));

    passwordScreenSubmit($user, 'Wrong-Granite-Window-99')->assertRedirect(route('admin.login'));

    $this->assertGuest();
    expect(session(EnsureStaff::NOTICE_KEY))->toBe(__('admin.confirm_password.logged_out'));
});

it('never accepts any password through the dev login', function () {
    app()['env'] = 'local';
    config(['admin.dev_login' => true, 'app.debug' => true]);

    $user = passwordScreenUser();

    passwordScreenSubmit($user, 'anything')->assertHasErrors(['currentPassword']);
    expect(Hash::check(STAFF_PW_CURRENT, $user->fresh()->password))->toBeTrue();
});

it('refuses under the lock when the session epoch no longer matches the row', function () {
    $user = passwordScreenUser();
    DB::table('users')->where('id', $user->id)->update(['session_epoch' => 5]);
    $before = $user->fresh()->password;

    $saved = app(ChangeOwnPassword::class)->handle($user, 4, STAFF_PW_CURRENT, STAFF_PW_NEW, '127.0.0.1');

    expect($saved)->toBeNull()
        ->and($user->fresh())->password->toBe($before)->session_epoch->toBe(5)
        ->and(SettingsAudit::query()->count())->toBe(0);
});

it('refuses under the lock when the current password no longer matches the row', function () {
    $user = passwordScreenUser();

    // The screen checked the old hash; another change landed before the lock.
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('Somebody-Else-Changed-55')]);

    expect(app(ChangeOwnPassword::class)->handle($user, 0, STAFF_PW_CURRENT, STAFF_PW_NEW, '127.0.0.1'))->toBeNull()
        ->and(Hash::check('Somebody-Else-Changed-55', $user->fresh()->password))->toBeTrue();
});

it('refuses under the lock for a user deactivated in the meantime', function () {
    $user = passwordScreenUser();
    DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

    expect(app(ChangeOwnPassword::class)->handle($user, 0, STAFF_PW_CURRENT, STAFF_PW_NEW, '127.0.0.1'))->toBeNull()
        ->and(Hash::check(STAFF_PW_CURRENT, $user->fresh()->password))->toBeTrue();
});

it('signs this browser out when the action refuses, without rotating the remember token', function () {
    $user = passwordScreenUser();
    $token = $user->fresh()->remember_token;

    $this->mock(ChangeOwnPassword::class)->shouldReceive('handle')->once()->andReturnNull();

    passwordScreenSubmit($user)->assertRedirect(route('admin.login'))->assertSet('newPassword', '');

    $this->assertGuest();
    expect($user->fresh()->remember_token)->toBe($token)
        ->and(session(EnsureStaff::NOTICE_KEY))->toBe(__('admin.auth.session_ended'));
});

it('writes no password to the logs, the audit or the snapshot', function () {
    $user = passwordScreenUser();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    passwordScreenSubmit($user, 'Wrong-SECRET-Window-99');
    $component = passwordScreenSubmit($user->fresh());

    expect(implode(' ', $logged))->not->toContain('SECRET')->not->toContain(STAFF_PW_NEW)->not->toContain(STAFF_PW_CURRENT)
        ->and(json_encode(SettingsAudit::query()->get()->toArray()))->not->toContain(STAFF_PW_NEW)
        ->and(json_encode($component->snapshot))->not->toContain(STAFF_PW_NEW);
});
