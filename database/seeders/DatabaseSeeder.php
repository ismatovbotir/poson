<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Local / testing seed: roles and permissions, the catalog base data
 * (categories, attributes, brands), demo products on local / staging,
 * plus a non-staff test user.
 *
 * Production deploys do not run this seeder; they run
 * `php artisan db:seed --class=RolesAndPermissionsSeeder --force`
 * and create staff with `php artisan admin:create-user`.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CatalogSeeder::class,
        ]);

        // Demo catalog: local and staging only, never production.
        if (app()->environment(['local', 'staging'])) {
            $this->call(DemoProductsSeeder::class);
        }

        if (app()->environment(['local', 'testing'])) {
            User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                User::factory()->make(['name' => 'Test User'])->only(['name', 'password', 'locale']),
            );
        }
    }
}
