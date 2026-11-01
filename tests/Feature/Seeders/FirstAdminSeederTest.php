<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\FirstAdminSeeder;
use Database\Seeders\LaunchSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

const FIRST_ADMIN_PASSWORD = 'fake-Launch-pw-2026';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Run FirstAdminSeeder with the given config; returns the exception message (or null).
 */
function runFirstAdminSeeder(mixed $login, mixed $password): ?string
{
    config(['admin.seed_login' => $login, 'admin.seed_password' => $password]);

    try {
        app(FirstAdminSeeder::class)->setContainer(app())->__invoke();
    } catch (RuntimeException $exception) {
        if (filled($password)) {
            // The message and the frames from the seeder down (not this helper or the test's arguments) never hold the password.
            $frames = [];

            foreach ($exception->getTrace() as $frame) {
                if (($frame['function'] ?? '') === 'runFirstAdminSeeder') {
                    break;
                }

                $frames[] = $frame;
            }

            expect($exception->getMessage())->not->toContain((string) $password)
                ->and(json_encode(array_column($frames, 'args'), JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain((string) $password);
        }

        return $exception->getMessage();
    }

    return null;
}

it('refuses invalid settings with a clear message and creates no user', function (mixed $login, mixed $password, string $names) {
    $message = runFirstAdminSeeder($login, $password);

    expect($message)->toContain('First admin not created')
        ->and($message)->toContain($names)
        ->and(User::query()->count())->toBe(0);
})->with([
    'missing login' => [null, FIRST_ADMIN_PASSWORD, 'ADMIN_SEED_LOGIN'],
    'empty login' => ['   ', FIRST_ADMIN_PASSWORD, 'ADMIN_SEED_LOGIN'],
    'invalid email' => ['not-an-email', FIRST_ADMIN_PASSWORD, 'ADMIN_SEED_LOGIN'],
    'empty password' => ['owner@poson.test', '', 'ADMIN_SEED_PASSWORD'],
    'missing password' => ['owner@poson.test', null, 'ADMIN_SEED_PASSWORD'],
    '11-character password' => ['owner@poson.test', 'fake-pw-11c', 'ADMIN_SEED_PASSWORD'],
]);

it('creates exactly one active Admin from a valid login (trimmed and lower-cased)', function () {
    expect(runFirstAdminSeeder('  Owner@Poson.TEST ', FIRST_ADMIN_PASSWORD))->toBeNull();

    $user = User::query()->sole();

    expect($user->email)->toBe('owner@poson.test')
        ->and($user->hasRole(Role::Admin->value))->toBeTrue()
        ->and($user->roles()->count())->toBe(1)
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check(FIRST_ADMIN_PASSWORD, $user->password))->toBeTrue();
});

it('accepts exactly 12 characters', function () {
    expect(runFirstAdminSeeder('owner@poson.test', 'fake-pw-12ch'))->toBeNull()
        ->and(User::query()->count())->toBe(1);
});

it('changes nothing for an existing email: role and password hash stay', function () {
    $existing = User::factory()->withRole(Role::SalesManager)->create(['email' => 'owner@poson.test']);
    $hash = $existing->password;

    expect(runFirstAdminSeeder('owner@poson.test', FIRST_ADMIN_PASSWORD))->toBeNull();

    $existing->refresh();

    expect(User::query()->count())->toBe(1)
        ->and($existing->password)->toBe($hash)
        ->and($existing->getRoleNames()->all())->toBe([Role::SalesManager->value]);
});

it('skips the step on a re-run once the settings are removed and an Admin exists', function () {
    User::factory()->withRole(Role::Admin)->create(['email' => 'owner@poson.test']);

    expect(runFirstAdminSeeder(null, null))->toBeNull()
        ->and(User::query()->count())->toBe(1);
});

it('never prints the password in the seeder output', function () {
    Storage::fake('local');
    Storage::fake('public');
    app()['env'] = 'production';
    config(['admin.seed_login' => 'owner@poson.test', 'admin.seed_password' => FIRST_ADMIN_PASSWORD]);

    $this->artisan('db:seed', ['--class' => LaunchSeeder::class, '--force' => true])
        ->expectsOutputToContain('owner@poson.test')
        ->doesntExpectOutputToContain(FIRST_ADMIN_PASSWORD)
        ->assertSuccessful();

    // A re-run prints the notice, still without the password.
    $this->artisan('db:seed', ['--class' => LaunchSeeder::class, '--force' => true])
        ->expectsOutputToContain('already exists')
        ->doesntExpectOutputToContain(FIRST_ADMIN_PASSWORD)
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
});

it('skips with a warning and creates no second Admin when an Admin exists, whatever the seed variables say', function () {
    User::factory()->withRole(Role::Admin)->create(['email' => 'first@poson.test']);
    config(['admin.seed_login' => 'stale@poson.test', 'admin.seed_password' => FIRST_ADMIN_PASSWORD]);

    $this->artisan('db:seed', ['--class' => FirstAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('An active Admin already exists')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'stale@poson.test')->exists())->toBeFalse();
});
