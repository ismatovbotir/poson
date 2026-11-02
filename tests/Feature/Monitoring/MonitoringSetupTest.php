<?php

use App\Enums\Role;
use App\Jobs\RecordWorkerHeartbeat;
use App\Models\MonitoringAction;
use App\Models\MonitoringState;
use App\Models\ScheduledTaskRun;
use App\Support\Monitoring\Heartbeat;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

function runPermissionMigration(): void
{
    (require database_path('migrations/2026_11_02_022450_add_monitoring_permissions.php'))->up();
}

it('gives the monitoring permissions to the Admin role only, idempotently', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    runPermissionMigration();
    runPermissionMigration();

    expect(Permission::query()->whereIn('name', ['monitoring.view', 'monitoring.manage'])->count())->toBe(2)
        ->and(SpatieRole::findByName(Role::Admin->value)->hasPermissionTo('monitoring.manage'))->toBeTrue()
        ->and(SpatieRole::findByName(Role::Admin->value)->permissions()->where('name', 'like', 'monitoring.%')->count())->toBe(2);

    foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
        expect(SpatieRole::findByName($role->value)->permissions()->where('name', 'like', 'monitoring.%')->count())->toBe(0);
    }
});

it('creates the permissions and the Admin role on a database without a seed', function () {
    runPermissionMigration();

    expect(SpatieRole::findByName(Role::Admin->value)->hasPermissionTo('monitoring.view'))->toBeTrue();
});

it('keeps the monitoring permissions Admin only after the seeder runs', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(SpatieRole::findByName(Role::Admin->value)->hasPermissionTo('monitoring.view'))->toBeTrue()
        ->and(SpatieRole::findByName(Role::SalesManager->value)->hasPermissionTo('monitoring.view'))->toBeFalse();
});

it('ticks the worker heartbeat with the lag from the job', function () {
    $job = new RecordWorkerHeartbeat;
    $job->dispatchedAt = microtime(true) - 2;
    $job->handle();

    $state = MonitoringState::query()->findOrFail(Heartbeat::WORKER);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(600)
        ->and($state->meta['lag_ms'])->toBeGreaterThanOrEqual(2000)
        ->and(Heartbeat::age(Heartbeat::WORKER))->toBeLessThan(5)
        ->and(Heartbeat::age('heartbeat:never'))->toBeNull();
});

it('prunes task runs after 14 days and audit rows after 365 days', function () {
    ScheduledTaskRun::factory()->create(['started_at' => now()->subDays(15)]);
    $keptRun = ScheduledTaskRun::factory()->create(['started_at' => now()->subDays(13)]);
    MonitoringAction::factory()->create(['created_at' => now()->subDays(366)]);
    $keptAction = MonitoringAction::factory()->create(['created_at' => now()->subDays(364)]);

    $this->artisan('model:prune', ['--model' => [ScheduledTaskRun::class, MonitoringAction::class]])->assertSuccessful();

    expect(ScheduledTaskRun::query()->pluck('id')->all())->toBe([$keptRun->id])
        ->and(MonitoringAction::query()->pluck('id')->all())->toBe([$keptAction->id]);
});
