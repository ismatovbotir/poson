<?php

use App\Enums\Role;
use App\Models\Setting;
use App\Support\CompanySettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Plan 40 (YD-10, YD-15): analytics.export is Admin only; no new secrets or env() reads.
 */
it('grants analytics.export to Admin only (migration is idempotent)', function () {
    foreach (Role::cases() as $role) {
        SpatieRole::findOrCreate($role->value, RolesAndPermissionsSeeder::GUARD);
    }

    $migration = require database_path('migrations/2026_11_24_131309_add_analytics_export_permission.php');
    $migration->up();
    $migration->up();

    expect(Permission::findByName('analytics.export', RolesAndPermissionsSeeder::GUARD)->roles->pluck('name')->all())->toBe([Role::Admin->value]);
});

it('mirrors analytics.export in the seeder for Admin only', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    foreach (Role::cases() as $role) {
        expect(SpatieRole::findByName($role->value, RolesAndPermissionsSeeder::GUARD)->hasPermissionTo('analytics.export'))->toBe($role === Role::Admin);
    }
});

it('reads no env() and holds no keys in the plan 40 code', function (string $path) {
    $source = file_get_contents(base_path($path));

    expect($source)->not->toMatch('/\benv\(/')
        ->not->toMatch('/(api[_-]?key|secret|token)\s*=>\s*[\'"][^\'"]+/i');
})->with([
    'app/Support/Analytics/AdClickIds.php',
    'app/Support/Analytics/CampaignReport.php',
    'app/Actions/Analytics/ExportOfflineConversions.php',
    'app/Actions/Analytics/PruneAdClickIds.php',
    'app/Actions/Settings/UpdateAdClickIds.php',
    'app/Http/Controllers/Admin/OfflineConversionsCsvController.php',
    'app/Livewire/Admin/Analytics/Campaigns.php',
    'app/Livewire/Site/Concerns/DispatchesAnalyticsGoals.php',
    'resources/js/analytics.js',
    'resources/js/order-placed.js',
    'resources/js/order-url.js',
]);

it('YD-15: Clean-param appears once, only in the Yandex group', function () {
    Setting::query()->create(['key' => CompanySettings::INDEXING_KEY, 'group' => 'seo', 'value' => true]);
    CompanySettings::flush();

    $robots = $this->get('/robots.txt')->assertOk()->getContent();
    $groups = explode("\n\n", $robots);
    $withClean = array_values(array_filter($groups, fn (string $group): bool => str_contains($group, 'Clean-param')));

    expect(substr_count($robots, 'Clean-param:'))->toBe(1)
        ->and($withClean)->toHaveCount(1)
        ->and($withClean[0])->toStartWith("User-agent: Yandex\n")
        ->and($withClean[0])->toEndWith("\nClean-param: yclid&ysclid&gclid&utm_source&utm_medium&utm_campaign&utm_term&utm_content");
});
