<?php

use App\Actions\Auth\AuthenticateStaff;
use App\Enums\AuditAction;
use App\Enums\McpRevokeReason;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Jobs\SendTelegramMessage;
use App\Models\Activity;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\CommandCatalog;
use App\Support\Admin\TempPassword;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

const STAFF_CLI_TEMP = 'FakeTemp8Pass4Cli';

/**
 * The one generator returns a known fake value (never a real password in tests).
 */
function staffCliFakeGenerator(): void
{
    app()->instance(TempPassword::class, new class extends TempPassword
    {
        public function generate(): string
        {
            return STAFF_CLI_TEMP;
        }
    });
}

function staffCliUser(array $attributes = []): User
{
    return User::factory()->withRole(Role::SalesManager)->create(['email' => 'staff.cli@poson.test', ...$attributes]);
}

beforeEach(function () {
    staffCliFakeGenerator();
    Queue::fake();
});

it('gives an active staff member a temporary password, printed once', function () {
    $user = staffCliUser();
    $token = $user->remember_token;

    $this->artisan('staff:reset-password', ['email' => ' Staff.CLI@poson.test '])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->expectsOutput(STAFF_CLI_TEMP)
        ->assertSuccessful();

    $fresh = $user->fresh();

    expect(Hash::check(STAFF_CLI_TEMP, $fresh->password))->toBeTrue()
        ->and($fresh->session_epoch)->toBe(1)
        ->and($fresh->remember_token)->not->toBe($token)
        ->and($fresh->mustChangePassword())->toBeTrue()
        ->and($fresh->password_changed_at)->not->toBeNull()
        ->and((int) round(now()->diffInHours($fresh->temp_password_expires_at)))->toBe(24);
});

it('writes one audit row with a null user and no name, email or value', function () {
    $user = staffCliUser();

    $this->artisan('staff:reset-password', ['email' => $user->email])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->assertSuccessful();

    expect(SettingsAudit::query()->sole())
        ->area->toBe(SettingsArea::Users)
        ->key->toBe('user.'.$user->id.'.password.cli')
        ->action->toBe(AuditAction::PasswordReset)
        ->user_id->toBeNull()
        ->ip->toBeNull();
});

it('changes nothing when the confirmation is declined', function () {
    $user = staffCliUser();
    $before = $user->fresh()->password;

    $this->artisan('staff:reset-password', ['email' => $user->email])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'no')
        ->doesntExpectOutput(STAFF_CLI_TEMP)
        ->assertFailed();

    expect($user->fresh())->password->toBe($before)->session_epoch->toBe(0)
        ->and(SettingsAudit::query()->count())->toBe(0);
});

it('changes nothing without an interactive confirmation', function () {
    $user = staffCliUser();
    $before = $user->fresh()->password;

    $exitCode = $this->withoutMockingConsoleOutput()->artisan('staff:reset-password', ['email' => $user->email, '--no-interaction' => true]);

    expect($exitCode)->toBe(1)
        ->and($user->fresh()->password)->toBe($before);
});

it('refuses unknown, non-staff and inactive accounts the same way, and never reactivates', function (Closure $makeUser) {
    $user = $makeUser();
    $before = $user?->fresh()->password;

    $this->artisan('staff:reset-password', ['email' => 'staff.cli@poson.test'])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->expectsOutputToContain(__('staff.reset_command.refused'))
        ->doesntExpectOutput(STAFF_CLI_TEMP)
        ->assertFailed();

    expect(SettingsAudit::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    if ($user !== null) {
        expect($user->fresh())->password->toBe($before)->session_epoch->toBe(0)
            ->and($user->fresh()->is_active)->toBe($user->is_active);
    }
})->with([
    'unknown' => [fn () => null],
    'no staff role' => [fn () => User::factory()->create(['email' => 'staff.cli@poson.test'])],
    'inactive staff' => [fn () => User::factory()->withRole(Role::Admin)->inactive()->create(['email' => 'staff.cli@poson.test'])],
]);

it('ends the sessions of the account and revokes its MCP tokens', function () {
    [$admin, $token] = mcpHolder();
    Auth::guard('web')->login($admin);
    auth()->forgetGuards();
    $this->get('/admin')->assertOk();

    $this->artisan('staff:reset-password', ['email' => $admin->email])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->assertSuccessful();

    expect($token->fresh())
        ->revoked_at->not->toBeNull()
        ->revoked_reason->toBe(McpRevokeReason::PasswordChanged);

    auth()->forgetGuards();
    $this->get('/admin')->assertRedirect(route('admin.login'));
    $this->assertGuest();
});

it('lets the temporary password sign in once, then forces the change', function () {
    $user = staffCliUser();
    RateLimiter::clear(AuthenticateStaff::throttleKey($user->email, '127.0.0.1'));
    RateLimiter::clear(AuthenticateStaff::ipThrottleKey('127.0.0.1'));

    $this->artisan('staff:reset-password', ['email' => $user->email])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->assertSuccessful();

    app(AuthenticateStaff::class)->handle($user->email, STAFF_CLI_TEMP, false, '127.0.0.1');
    auth()->forgetGuards();

    $this->get('/admin')->assertRedirect(route('admin.account.password'));
});

it('alerts the management chat with the escaped name and no password', function () {
    mcpTelegramConfigured();
    $user = staffCliUser(['name' => '<b>Ali</b> Valiyev']);

    $this->artisan('staff:reset-password', ['email' => $user->email])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->doesntExpectOutputToContain(__('staff.reset_command.alert_undeliverable'))
        ->assertSuccessful();

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => $job->chat === 'management'
        && str_contains($job->html, e('<b>Ali</b> Valiyev'))
        && ! str_contains($job->html, STAFF_CLI_TEMP)
        && ! str_contains($job->html, $user->email));
});

it('warns when the alert cannot be delivered, and still finishes', function () {
    freshSecrets(['services.telegram.bot_token' => null, 'services.telegram.chats.management' => null]);
    $user = staffCliUser();

    $this->artisan('staff:reset-password', ['email' => $user->email])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->expectsOutput(STAFF_CLI_TEMP)
        ->expectsOutputToContain(__('staff.reset_command.alert_undeliverable'))
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('keeps the temporary password out of logs, audit, activities and alerts', function () {
    mcpTelegramConfigured();
    $user = staffCliUser();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    $this->artisan('staff:reset-password', ['email' => $user->email])
        ->expectsConfirmation(__('staff.reset_command.confirm'), 'yes')
        ->assertSuccessful();

    expect(implode(' ', $logged))->not->toContain(STAFF_CLI_TEMP)
        ->and(json_encode(SettingsAudit::query()->get()->toArray()))->not->toContain(STAFF_CLI_TEMP)
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain(STAFF_CLI_TEMP)
        ->and(json_encode(DB::table('settings_audits')->get()))->not->toContain($user->email);

    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => ! str_contains(serialize($job), STAFF_CLI_TEMP));
});

it('documents itself in English, without a password, and lists only as text', function () {
    Cache::flush();

    $command = Artisan::all()['staff:reset-password'];
    $item = collect(CommandCatalog::all())->firstWhere('name', 'staff:reset-password');

    expect($command->getDescription())->not->toBeEmpty()
        ->and($command->getHelp())->toContain('24 hours')->not->toContain(STAFF_CLI_TEMP)
        ->and($item)->not->toBeNull()
        ->and($item['schedule'])->toBe([])
        ->and($item['help'])->not->toContain(STAFF_CLI_TEMP);
});

it('is not scheduled and not a manual task', function () {
    $scheduled = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode(' ');

    expect($scheduled)->not->toContain('staff:reset-password')
        ->and((array) config('monitoring.manual_tasks', []))->not->toContain('staff:reset-password');
});

it('generates temporary passwords of 16 letters and digits, 24 hours valid, no two alike', function () {
    app()->forgetInstance(TempPassword::class);
    $generator = app(TempPassword::class);

    $first = $generator->generate();

    expect($first)->toHaveLength(16)->toMatch('/^[A-Za-z0-9]+$/')
        ->and($generator->generate())->not->toBe($first)
        ->and((int) round(now()->diffInHours($generator->expiresAt())))->toBe(24);
});
