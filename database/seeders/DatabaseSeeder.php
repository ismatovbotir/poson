<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Local / testing seed: roles and permissions, the catalog base data
 * (categories, attributes, brands), the FAQ, demo products on local /
 * staging (and demo leads with customers, DemoLeadsSeeder, and demo news,
 * DemoNewsSeeder), plus a non-staff
 * test user.
 *
 * Production deploys do not run this seeder; they run
 * `php artisan db:seed --class=RolesAndPermissionsSeeder --force`
 * (and `--class=FaqSeeder` once the categories exist, `--class=PagesSeeder`
 * and `--class=ArticlesSeeder` (draft articles, after the categories) at
 * launch; all idempotent)
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
            // Real FAQ content (general + top-level categories), every environment.
            FaqSeeder::class,
            // Starter static pages and their FAQ (plan 07), every environment; never overwrites.
            PagesSeeder::class,
            // Knowledge articles as drafts for review (decision 2026-10-24), every environment; needs the
            // categories (after CatalogSeeder); never overwrites or re-creates a deleted article.
            ArticlesSeeder::class,
        ]);

        // Demo catalog: local and staging only, never production.
        if (app()->environment(['local', 'staging'])) {
            $this->call([DemoProductsSeeder::class, DemoLeadsSeeder::class, DemoNewsSeeder::class]);
        }

        if (app()->environment(['local', 'testing'])) {
            User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                User::factory()->make(['name' => 'Test User'])->only(['name', 'password', 'locale']),
            );
        }
    }
}
