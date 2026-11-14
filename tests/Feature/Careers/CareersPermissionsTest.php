<?php

use App\Enums\Role;
use App\Models\JobApplication;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

it('grants the careers permissions to Admin only (migration is idempotent)', function () {
    foreach (Role::cases() as $role) {
        SpatieRole::findOrCreate($role->value, RolesAndPermissionsSeeder::GUARD);
    }

    $migration = require database_path('migrations/2026_11_14_183404_add_careers_permissions.php');
    $migration->up();
    $migration->up();

    foreach (['careers.vacancies', 'careers.applications', 'careers.cv'] as $name) {
        expect(Permission::findByName($name, RolesAndPermissionsSeeder::GUARD)->roles->pluck('name')->all())->toBe([Role::Admin->value]);
    }
});

it('mirrors the careers permissions in the seeder for Admin only', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    foreach (Role::cases() as $role) {
        $has = SpatieRole::findByName($role->value, RolesAndPermissionsSeeder::GUARD)->hasPermissionTo('careers.cv');
        expect($has)->toBe($role === Role::Admin);
    }
});

it('allows the CV download only for the Admin role', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $application = JobApplication::factory()->openApplication()->create();

    expect(User::factory()->withRole(Role::Admin)->create()->can('downloadCv', $application))->toBeTrue();

    // A permission handed to another role by mistake is still not enough.
    $sales = User::factory()->withRole(Role::SalesManager)->create();
    $sales->givePermissionTo('careers.cv');
    expect($sales->can('downloadCv', $application))->toBeFalse();
});

it('registers the job_application morph key', function () {
    expect((new JobApplication)->getMorphClass())->toBe('job_application');
});
