<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

const REPAIRS_PERMISSIONS = ['repairs.viewAny', 'repairs.view', 'repairs.create', 'repairs.update', 'repairs.assign', 'repairs.view-all', 'repairs.costs'];

/**
 * Runs the add-only permissions migration file.
 */
function repairsRunPermissionsMigration(): void
{
    $files = glob(database_path('migrations/*_add_repairs_permissions.php'));
    expect($files)->toHaveCount(1);

    (require $files[0])->up();
}

it('grants the exact repairs role matrix from the seeder', function (Role $role, array $allowed) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->withRole($role)->create();

    foreach (REPAIRS_PERMISSIONS as $permission) {
        // Admin passes can() through Gate::before: check the role's stored permissions instead.
        $has = SpatieRole::findByName($role->value, 'web')->hasPermissionTo($permission);

        expect($has)->toBe(in_array($permission, $allowed, true), "{$role->value}: {$permission}");
    }

    expect($user->getAllPermissions()->pluck('name')->filter(fn (string $name): bool => str_starts_with($name, 'repairs.'))->sort()->values()->all())
        ->toBe(collect($allowed)->sort()->values()->all());
})->with([
    'admin' => [Role::Admin, REPAIRS_PERMISSIONS],
    'sales manager' => [Role::SalesManager, ['repairs.viewAny', 'repairs.view', 'repairs.view-all', 'repairs.costs']],
    'service engineer' => [Role::ServiceEngineer, ['repairs.viewAny', 'repairs.view', 'repairs.create', 'repairs.update', 'repairs.costs']],
    'content manager' => [Role::ContentManager, []],
]);

it('never lets engineers assign or create customers, nor sales manager edit repairs', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    $sales = User::factory()->withRole(Role::SalesManager)->create();

    expect($engineer->can('repairs.assign'))->toBeFalse()
        ->and($engineer->can('repairs.view-all'))->toBeFalse()
        ->and($engineer->can('customers.create'))->toBeFalse()
        ->and($sales->can('repairs.update'))->toBeFalse()
        ->and($sales->can('repairs.create'))->toBeFalse()
        ->and($sales->can('repairs.assign'))->toBeFalse();
});

it('adds the permissions with an idempotent, add-only migration that keeps manual role edits', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Production before the migration: the permissions do not exist yet.
    Permission::query()->where('name', 'like', 'repairs.%')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // A manual edit on the live role must survive.
    $manual = Permission::findOrCreate('catalog.manual-edit-probe', 'web');
    SpatieRole::findByName(Role::SalesManager->value, 'web')->givePermissionTo($manual);

    repairsRunPermissionsMigration();
    $count = fn (): int => DB::table('role_has_permissions')->count();
    $first = $count();
    repairsRunPermissionsMigration();

    expect($count())->toBe($first)
        ->and(Permission::query()->where('name', 'like', 'repairs.%')->count())->toBe(count(REPAIRS_PERMISSIONS))
        ->and(SpatieRole::findByName(Role::SalesManager->value, 'web')->hasPermissionTo('catalog.manual-edit-probe'))->toBeTrue()
        ->and(SpatieRole::findByName(Role::SalesManager->value, 'web')->hasPermissionTo('repairs.costs'))->toBeTrue()
        ->and(SpatieRole::findByName(Role::ServiceEngineer->value, 'web')->hasPermissionTo('repairs.update'))->toBeTrue()
        ->and(SpatieRole::findByName(Role::ServiceEngineer->value, 'web')->hasPermissionTo('repairs.assign'))->toBeFalse()
        ->and(SpatieRole::findByName(Role::ContentManager->value, 'web')->permissions()->where('name', 'like', 'repairs.%')->count())->toBe(0);
});

it('keeps the seeder and the migration in agreement', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $fromSeeder = DB::table('role_has_permissions')->count();

    DB::table('role_has_permissions')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    repairsRunPermissionsMigration();

    $repairsOnly = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('permissions.name', 'like', 'repairs.%')
        ->count();
    $seededRepairs = 7 + 4 + 5;

    expect($repairsOnly)->toBe($seededRepairs)->and($fromSeeder)->toBeGreaterThan($seededRepairs);
});

it('is not part of the launch seeder sync', function () {
    expect(file_get_contents(database_path('seeders/LaunchSeeder.php')))->not->toContain('repairs.');
});
