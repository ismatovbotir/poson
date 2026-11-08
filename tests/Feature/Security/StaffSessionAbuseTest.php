<?php

use App\Enums\McpRevokeReason;
use App\Enums\Role;
use App\Livewire\Admin\Account\Password;
use App\Livewire\Admin\Auth\ConfirmPassword;
use App\Models\Lead;
use App\Models\User;
use App\Support\Admin\StaffSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
| Plan 24 A1 sign-off, abuse cases 5, 13, 16 and the 10-failure sign-out.
| Case 16 is covered by tests/Feature/Admin/Account/PasswordScreenTest.php
| ("keeps this browser remembered after its own change, while another device
| cookie is refused"); it is not duplicated here.
*/

const ABUSE_PW_CURRENT = 'Old-Granite-Window-47';

const ABUSE_PW_NEW = 'Tall-Copper-Lantern-82';

const ABUSE_CUSTOMER = 'Zarnigor Maxfiyova';

beforeEach(function () {
    Http::preventStrayRequests();
});

function abuseUser(array $attributes = []): User
{
    $user = staffUser(Role::Admin);
    $user->forceFill(['password' => Hash::make(ABUSE_PW_CURRENT), ...$attributes])->saveQuietly();

    return $user;
}

function abuseChangeOwnPassword(User $user, string $current = ABUSE_PW_CURRENT): void
{
    Livewire::actingAs($user)->test(Password::class)
        ->set('currentPassword', $current)
        ->set('newPassword', ABUSE_PW_NEW)
        ->set('newPasswordConfirmation', ABUSE_PW_NEW)
        ->call('save');
}

function abuseWrongCurrent(User $user): Testable
{
    return Livewire::actingAs($user)->test(Password::class)
        ->set('currentPassword', 'Wrong-Granite-Window-99')
        ->set('newPassword', ABUSE_PW_NEW)
        ->set('newPasswordConfirmation', ABUSE_PW_NEW)
        ->call('save');
}

it('answers a forced-change user on a page route with 403 or the redirect, never data (case 5)', function () {
    Lead::factory()->create(['name' => ABUSE_CUSTOMER]);
    $user = abuseUser(['temp_password_expires_at' => now()->addHour()]);
    $this->actingAs($user);

    $response = $this->getJson(route('admin.leads.index'));

    expect($response->getStatusCode())->toBeIn([302, 403]);
    $response->assertDontSee(ABUSE_CUSTOMER);
    $this->assertAuthenticatedAs($user);
});

it('answers a forced-change user on /livewire/update with 403, never data (case 5)', function () {
    Lead::factory()->create(['name' => ABUSE_CUSTOMER]);
    $user = abuseUser();

    Auth::guard('web')->login($user);
    auth()->forgetGuards();

    $html = $this->get(route('admin.leads.index'))->assertOk()->assertSee(ABUSE_CUSTOMER)->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = collect($matches[1])
        ->map(fn (string $encoded): string => html_entity_decode($encoded, ENT_QUOTES))
        ->first(fn (string $json): bool => data_get(json_decode($json, true), 'memo.name') === 'admin.leads');

    DB::table('users')->where('id', $user->id)->update(['temp_password_expires_at' => now()->addHour()]);
    auth()->forgetGuards();

    $response = $this->postJson(app('livewire')->getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'calls' => [['method' => '$refresh', 'params' => [], 'metadata' => []]], 'updates' => []]],
    ], ['X-Livewire' => '1', 'Accept' => 'application/json']);

    $response->assertForbidden()->assertDontSee(ABUSE_CUSTOMER);
});

it('refuses an old MCP token on the real route after the own password change (case 13)', function () {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue());
    $admin->forceFill(['password' => Hash::make(ABUSE_PW_CURRENT)])->saveQuietly();
    mcpTelegramConfigured();

    mcpRequest($plain)->assertOk();

    abuseChangeOwnPassword($admin);

    expect($token->fresh()->revoked_reason)->toBe(McpRevokeReason::PasswordChanged);
    mcpRequest($plain)->assertUnauthorized();
});

it('refuses an old MCP token on the real route after the CLI recovery reset (case 13)', function () {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue());
    mcpTelegramConfigured();

    mcpRequest($plain)->assertOk();

    $this->artisan('staff:reset-password', ['email' => $admin->email])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->assertSuccessful();

    expect($token->fresh()->revoked_at)->not->toBeNull();
    mcpRequest($plain)->assertUnauthorized();
});

it('ends every session and remember cookie of other devices after 10 wrong current passwords', function () {
    $user = abuseUser();
    $recaller = Auth::guard('web')->getRecallerName();

    // Another device: a remember cookie and a session carrying the current epoch.
    Auth::guard('web')->login($user, true);
    $otherCookie = Cookie::queued($recaller)->getValue();
    Cookie::unqueue($recaller);
    $otherEpoch = (int) session(StaffSession::EPOCH_KEY);

    foreach (range(1, ConfirmPassword::MAX_CONSECUTIVE_FAILURES - 1) as $attempt) {
        RateLimiter::clear(ConfirmPassword::minuteKey($user));
        abuseWrongCurrent($user)->assertHasErrors('currentPassword');
    }
    RateLimiter::clear(ConfirmPassword::minuteKey($user));
    abuseWrongCurrent($user)->assertRedirect(route('admin.login'));

    // The other device's session (old epoch) is refused.
    session()->flush();
    session()->put(StaffSession::EPOCH_KEY, $otherEpoch);
    Auth::guard('web')->login($user->fresh());
    session()->put(StaffSession::EPOCH_KEY, $otherEpoch);
    auth()->forgetGuards();
    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    $this->assertGuest();

    // The other device's remember cookie is refused too.
    session()->flush();
    auth()->forgetGuards();
    Cookie::unqueue($recaller);
    $this->withCookie($recaller, $otherCookie)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    $this->assertGuest();
});
