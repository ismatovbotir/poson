<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a staff user from options', function () {
    $this->artisan('admin:create-user', [
        '--name' => 'Aziz Karimov',
        '--email' => 'Aziz@Poson.test',
        '--password' => 'secret-pass-123',
        '--role' => 'sales_manager',
        '--no-interaction' => true,
    ])->assertSuccessful();

    $user = User::where('email', 'aziz@poson.test')->firstOrFail();

    expect($user->name)->toBe('Aziz Karimov')
        ->and($user->hasRole(Role::SalesManager))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->locale)->toBe('uz')
        ->and(Hash::check('secret-pass-123', $user->password))->toBeTrue()
        ->and($user->can('products.viewAny'))->toBeTrue();
});

it('asks for missing values interactively', function () {
    $this->artisan('admin:create-user')
        ->expectsQuestion('Name', 'Malika Yusupova')
        ->expectsQuestion('Email', 'malika@poson.test')
        ->expectsQuestion('Role', 'content_manager')
        ->expectsQuestion('Password', 'secret-pass-123')
        ->expectsQuestion('Confirm password', 'secret-pass-123')
        ->assertSuccessful();

    expect(User::where('email', 'malika@poson.test')->first()?->hasRole(Role::ContentManager))->toBeTrue();
});

it('rejects a password confirmation mismatch', function () {
    $this->artisan('admin:create-user', ['--name' => 'A', '--email' => 'a@poson.test', '--role' => 'admin'])
        ->expectsQuestion('Password', 'secret-pass-123')
        ->expectsQuestion('Confirm password', 'something-else')
        ->assertFailed();

    expect(User::where('email', 'a@poson.test')->exists())->toBeFalse();
});

it('normalizes the email before checking uniqueness', function () {
    User::factory()->create(['email' => 'taken@poson.test']);

    $this->artisan('admin:create-user', [
        '--name' => 'Aziz',
        '--email' => '  Taken@Poson.TEST ',
        '--password' => 'secret-pass-123',
        '--role' => 'admin',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::count())->toBe(1);
});

it('validates the input', function (array $options) {
    User::factory()->create(['email' => 'taken@poson.test']);

    $this->artisan('admin:create-user', [...[
        '--name' => 'Aziz',
        '--email' => 'new@poson.test',
        '--password' => 'secret-pass-123',
        '--role' => 'admin',
        '--no-interaction' => true,
    ], ...$options])->assertFailed();

    expect(User::where('email', 'new@poson.test')->exists())->toBeFalse();
})->with([
    'invalid email' => [['--email' => 'nope']],
    'taken email' => [['--email' => 'taken@poson.test']],
    'short password' => [['--password' => 'short']],
    'unknown role' => [['--role' => 'manager']],
    'unknown locale' => [['--locale' => 'de']],
    'missing name' => [['--name' => null]],
]);
