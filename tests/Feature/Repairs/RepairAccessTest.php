<?php

use App\Enums\Role;
use App\Models\Repair;
use App\Models\User;

/**
 * Repairs per kind: the user's own, a colleague's and unassigned.
 *
 * @return array<string, Repair>
 */
function repairsAccessMatrix(User $user): array
{
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();

    return [
        'own' => Repair::factory()->assignedTo($user)->create(),
        'other' => Repair::factory()->assignedTo($colleague)->create(),
        'free' => Repair::factory()->create(),
    ];
}

it('scopes visible and editable repairs per role like the policy', function (Role $role, array $visible, array $editable) {
    $user = staffUser($role);
    $repairs = repairsAccessMatrix($user);

    $visibleIds = Repair::query()->visibleTo($user)->pluck('id')->all();
    $editableIds = Repair::query()->editableBy($user)->pluck('id')->all();

    foreach ($repairs as $key => $repair) {
        $shouldSee = in_array($key, $visible, true);
        $shouldEdit = in_array($key, $editable, true);

        expect(in_array($repair->id, $visibleIds, true))->toBe($shouldSee, "{$role->value} list: {$key}")
            ->and($user->can('view', $repair))->toBe($shouldSee, "{$role->value} view: {$key}")
            ->and(in_array($repair->id, $editableIds, true))->toBe($shouldEdit, "{$role->value} editable list: {$key}")
            ->and($user->can('update', $repair))->toBe($shouldEdit, "{$role->value} update: {$key}");
    }
})->with([
    'admin' => [Role::Admin, ['own', 'other', 'free'], ['own', 'other', 'free']],
    'sales manager (read-only)' => [Role::SalesManager, ['own', 'other', 'free'], []],
    'service engineer' => [Role::ServiceEngineer, ['own', 'free'], ['own', 'free']],
    'content manager' => [Role::ContentManager, [], []],
]);

it('uses the same level decision for the policy and the scope (one predicate)', function () {
    $source = file_get_contents(app_path('Models/Repair.php')).file_get_contents(app_path('Policies/RepairPolicy.php'));

    // Neither re-derives the rule from permissions: both go through RepairAccess.
    expect($source)->toContain('RepairAccess::viewLevel')
        ->and($source)->toContain('RepairAccess::editLevel')
        ->and(file_get_contents(app_path('Policies/RepairPolicy.php')))->not->toContain("can('repairs.view-all')")
        ->and(file_get_contents(app_path('Policies/RepairPolicy.php')))->toContain('isVisibleTo');
});

it('lets only the admin pick an engineer and only the assignee or the admin release', function () {
    $admin = staffUser();
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
    $free = Repair::factory()->create();
    $own = Repair::factory()->assignedTo($engineer)->create();

    expect($admin->can('assign', $free))->toBeTrue()
        ->and($engineer->can('assign', $free))->toBeFalse()
        ->and($engineer->can('take', $free))->toBeTrue()
        ->and($engineer->can('take', $own))->toBeFalse()
        ->and($engineer->can('release', $own))->toBeTrue()
        ->and($colleague->can('release', $own))->toBeFalse()
        ->and($colleague->can('view', $own))->toBeFalse();
});

it('shows costs to Admin, Sales and Engineer but not to the content manager, and edits only to Admin and Engineer', function () {
    $repair = Repair::factory()->create();

    foreach ([[Role::Admin, true, true], [Role::SalesManager, true, false], [Role::ServiceEngineer, true, true], [Role::ContentManager, false, false]] as [$role, $view, $edit]) {
        $user = staffUser($role);

        expect($user->can('viewCosts', $repair))->toBe($view, "{$role->value} view costs")
            ->and($user->can('editCosts', $repair))->toBe($edit, "{$role->value} edit costs");
    }
});
