<?php

use App\Actions\Auth\AuthenticateStaff;
use App\Enums\McpAuthType;
use App\Enums\McpCallStatus;
use App\Enums\McpDenyReason;
use App\Livewire\Admin\Auth\Login;
use App\Models\McpCall;
use App\Models\User;
use App\Support\Mcp\McpTokenWatcher;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/*
| Plan 13 M1 review follow-ups: password rehash, alert cap, schedule, enums.
*/

beforeEach(function () {
    mcpTelegramConfigured();
});

it('does not rehash passwords on login, so a login never revokes MCP access', function () {
    expect(config('hashing.rehash_on_login'))->toBeFalse();

    [$ceo, $token] = mcpHolder($plain = fakeMcpTokenValue());
    // A hash with another cost: Laravel would rehash it on login if rehash_on_login were on.
    $oldHash = Hash::make('secret-pass-123', ['rounds' => (int) config('hashing.bcrypt.rounds', 12) + 1]);
    DB::table('users')->where('id', $ceo->id)->update(['email' => 'ceo@poson.test', 'password' => $oldHash]);
    expect(Hash::needsRehash($oldHash))->toBeTrue();

    RateLimiter::clear(AuthenticateStaff::throttleKey('ceo@poson.test', '127.0.0.1'));
    RateLimiter::clear(AuthenticateStaff::ipThrottleKey('127.0.0.1'));

    Livewire::test(Login::class)->set('email', 'ceo@poson.test')->set('password', 'secret-pass-123')->call('login')->assertHasNoErrors();

    $this->assertAuthenticatedAs($ceo);
    expect(DB::table('users')->where('id', $ceo->id)->value('password'))->toBe($oldHash)
        ->and($token->fresh()->revoked_at)->toBeNull()
        ->and($ceo->fresh()->mcp_access_granted_at)->not->toBeNull();

    mcpRequest($plain)->assertOk();
});

it('alerts at most 10 new IPs per hour and alerts a suppressed IP after the window (C10)', function () {
    [$ceo] = mcpHolder($plain = fakeMcpTokenValue());
    $newIpAlerts = fn () => collect(mcpAlertTexts())->filter(fn ($text) => str_contains($text, __('telegram.mcp.events.new_ip', [], 'uz')))->count();

    // First use (its own alert), then 11 new addresses within the hour. Tool calls also write mcp_calls rows.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.200']);
    mcpCallTool($plain, 'get_my_access')->assertOk();

    foreach (range(1, McpTokenWatcher::MAX_ALERTS_PER_HOUR + 1) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i]);
        mcpCallTool($plain, 'get_my_access')->assertOk();
    }

    expect($newIpAlerts())->toBe(McpTokenWatcher::MAX_ALERTS_PER_HOUR);

    // The 11th address was suppressed, not remembered: still unknown in the same hour (no extra alert)...
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11']);
    mcpCallTool($plain, 'get_my_access')->assertOk();
    expect($newIpAlerts())->toBe(McpTokenWatcher::MAX_ALERTS_PER_HOUR);

    // ...and alerts on its next use after the window, even though mcp_calls has it.
    $this->travel(61)->minutes();
    mcpCallTool($plain, 'get_my_access')->assertOk();
    expect($newIpAlerts())->toBe(McpTokenWatcher::MAX_ALERTS_PER_HOUR + 1);

    // Now it is known: no more alerts.
    mcpCallTool($plain, 'get_my_access')->assertOk();
    expect($newIpAlerts())->toBe(McpTokenWatcher::MAX_ALERTS_PER_HOUR + 1);
});

it('schedules the MCP jobs once per server without overlapping', function (string $needle) {
    $event = collect(app(Schedule::class)->events())->first(fn (Event $event) => str_contains((string) $event->command, $needle));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
})->with([
    'idle revoke' => ['mcp:revoke-idle'],
    'sweep' => ['mcp:sweep'],
    'mcp_calls prune' => ['McpCall'],
]);

it('runs both MCP commands', function () {
    $this->artisan('mcp:revoke-idle')->expectsOutputToContain('0 idle and 0 never-used')->assertSuccessful();
    $this->artisan('mcp:sweep')->expectsOutputToContain('0 user(s)')->assertSuccessful();
});

it('stores deny reasons and the auth type as enums', function () {
    [$ceo, $token] = mcpHolder($plain = fakeMcpTokenValue());
    User::query()->whereKey($ceo->id)->update(['is_active' => false]);

    mcpRequest($plain)->assertForbidden();

    $call = McpCall::query()->sole();

    expect($call->status)->toBe(McpCallStatus::Denied)
        ->and($call->auth_type)->toBe(McpAuthType::Token)
        ->and($call->denyReason())->toBe(McpDenyReason::Inactive);

    foreach (['uz', 'ru', 'en'] as $locale) {
        foreach ([...McpDenyReason::cases(), ...McpAuthType::cases()] as $case) {
            $key = ($case instanceof McpDenyReason ? 'enums.mcp_deny_reason.' : 'enums.mcp_auth_type.').$case->value;
            expect(__($key, [], $locale))->not->toBe($key);
        }
    }
});
