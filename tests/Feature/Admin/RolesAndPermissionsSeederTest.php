<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

it('is idempotent', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $counts = fn (): array => [
        SpatieRole::count(),
        Permission::count(),
        DB::table('role_has_permissions')->count(),
    ];

    $first = $counts();
    $this->seed(RolesAndPermissionsSeeder::class);

    expect($counts())->toBe($first)
        ->and($first[0])->toBe(4)
        ->and($first[1])->toBe(count(RolesAndPermissionsSeeder::allPermissions()));
});

it('creates every staff role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(SpatieRole::pluck('name')->sort()->values()->all())
        ->toBe(collect(Role::values())->sort()->values()->all());
});

it('grants the catalog permissions per role (plan 03 §3)', function (Role $role, array $allowed, array $denied) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->withRole($role)->create();

    foreach ($allowed as $permission) {
        expect($user->can($permission))->toBeTrue("{$role->value} should have {$permission}");
    }

    foreach ($denied as $permission) {
        expect($user->can($permission))->toBeFalse("{$role->value} should not have {$permission}");
    }
})->with([
    'content manager' => [Role::ContentManager, ['categories.create', 'attributes.update', 'brands.delete', 'products.update', 'products.manage-services'], ['products.force-delete']],
    'sales manager' => [Role::SalesManager, ['categories.viewAny', 'brands.view', 'products.viewAny'], ['products.create', 'products.update', 'categories.delete', 'products.manage-services', 'products.force-delete']],
    'service engineer' => [Role::ServiceEngineer, ['products.viewAny', 'products.view', 'categories.view', 'products.manage-services'], ['products.update', 'products.delete', 'brands.create', 'products.force-delete']],
]);

it('grants the CRM permissions per role (plan 05 §5)', function (Role $role, array $allowed, array $denied) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->withRole($role)->create();

    foreach ($allowed as $permission) {
        expect($user->can($permission))->toBeTrue("{$role->value} should have {$permission}");
    }

    foreach ($denied as $permission) {
        expect($user->can($permission))->toBeFalse("{$role->value} should not have {$permission}");
    }
})->with([
    'sales manager' => [Role::SalesManager,
        ['leads.viewAny', 'leads.view', 'leads.create', 'leads.update', 'leads.sales', 'leads.service-view', 'leads.reassign', 'customers.viewAny', 'customers.view', 'customers.create', 'customers.update'],
        ['leads.delete', 'leads.service', 'leads.view-all', 'leads.assign', 'customers.delete', 'customers.update-any', 'customers.price-level']],
    'service engineer' => [Role::ServiceEngineer,
        ['leads.viewAny', 'leads.view', 'leads.create', 'leads.update', 'leads.service', 'customers.viewAny', 'customers.view'],
        ['leads.delete', 'leads.sales', 'leads.service-view', 'leads.view-all', 'leads.assign', 'leads.reassign', 'customers.create', 'customers.update', 'customers.price-level']],
    'content manager' => [Role::ContentManager,
        [],
        ['leads.viewAny', 'leads.create', 'leads.sales', 'leads.service', 'customers.viewAny']],
]);

it('gives the admin role every permission, the CRM ones included', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = SpatieRole::findByName(Role::Admin->value, RolesAndPermissionsSeeder::GUARD);

    expect($admin->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(RolesAndPermissionsSeeder::allPermissions())->sort()->values()->all())
        ->and(RolesAndPermissionsSeeder::allPermissions())->toContain('leads.reassign', 'leads.assign', 'leads.view-all', 'customers.price-level');
});

it('lets the admin role do anything through Gate::before', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();

    expect($admin->can('products.force-delete'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('some.ability-that-does-not-exist'))->toBeTrue();

    $contentManager = User::factory()->withRole(Role::ContentManager)->create();

    expect(Gate::forUser($contentManager)->allows('some.ability-that-does-not-exist'))->toBeFalse();
});

it('is called from the database seeder, which can run twice', function () {
    $this->seed();
    $this->seed();

    expect(SpatieRole::count())->toBe(4)
        ->and(User::where('email', 'test@example.com')->count())->toBe(1)
        ->and(User::where('email', 'test@example.com')->first()->isStaff())->toBeFalse();
});
