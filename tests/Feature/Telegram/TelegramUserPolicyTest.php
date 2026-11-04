<?php

use App\Enums\Role;
use App\Models\TelegramUser;
use App\Models\User;
use App\Policies\TelegramUserPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 14 v2 §4 / §10, TG-35: assign and ban build on view.
 */
it('registers the policy', function () {
    expect(Gate::getPolicyFor(TelegramUser::class))->toBeInstanceOf(TelegramUserPolicy::class);
});

it('authorizes each ability per role and ownership', function (Role $role, string $owner, array $allowed) {
    $user = staffUser($role);
    $thread = match ($owner) {
        'own' => TelegramUser::factory()->registered()->assigned($user)->create(),
        'free' => TelegramUser::factory()->registered()->create(),
        'other' => TelegramUser::factory()->registered()->assigned(User::factory()->withRole(Role::SalesManager)->create())->create(),
        'unregistered' => TelegramUser::factory()->unregistered()->assigned($user)->create(),
    };

    foreach (['view', 'reply', 'convert', 'close', 'assign', 'ban', 'delete'] as $ability) {
        expect($user->can($ability, $thread))->toBe(in_array($ability, $allowed, true), "{$role->value}/{$owner}/{$ability}");
    }

    expect($user->can('viewAny', TelegramUser::class))->toBe(in_array($role, [Role::Admin, Role::SalesManager], true));
})->with([
    // Covers visibility (view and the abilities built on it) only; the per-permission gates have their own test below.
    'admin: other' => [Role::Admin, 'other', ['view', 'reply', 'convert', 'close', 'assign', 'ban', 'delete']],
    'admin: own' => [Role::Admin, 'own', ['view', 'reply', 'convert', 'close', 'assign', 'ban', 'delete']],
    'sales: own' => [Role::SalesManager, 'own', ['view', 'reply', 'convert', 'close', 'assign', 'ban']],
    'sales: unassigned' => [Role::SalesManager, 'free', ['view', 'reply', 'convert', 'close', 'assign', 'ban']],
    'sales: other manager' => [Role::SalesManager, 'other', []],
    'sales: unregistered (T21-11)' => [Role::SalesManager, 'unregistered', []],
    'admin: unregistered' => [Role::Admin, 'unregistered', ['view', 'reply', 'convert', 'close', 'assign', 'ban', 'delete']],
    'content: unassigned' => [Role::ContentManager, 'free', []],
    'service: unassigned' => [Role::ServiceEngineer, 'free', []],
]);

it('keeps assign and ban behind their own permissions even on a visible thread', function () {
    $user = staffUser(Role::SalesManager);
    Spatie\Permission\Models\Role::findByName(Role::SalesManager->value, 'web')->revokePermissionTo(['telegram.reassign', 'telegram.ban']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $thread = TelegramUser::factory()->registered()->assigned($user)->create();
    $user = $user->fresh();

    expect($user->can('view', $thread))->toBeTrue()
        ->and($user->can('assign', $thread))->toBeFalse()
        ->and($user->can('ban', $thread))->toBeFalse();
});
