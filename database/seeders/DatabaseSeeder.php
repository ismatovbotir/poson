<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local / staging / testing seed: roles and permissions, the catalog base
 * data (categories, attributes, brands), the FAQ, starter pages, draft
 * articles, the real draft products (local / staging only, ProductsSeeder),
 * demo news and banners (local / staging only, DemoSeeding) and demo leads with customers (local / staging only,
 * DemoLeadsSeeder), plus the first Admin when ADMIN_SEED_LOGIN is set
 * (local / staging) and a non-staff test user (local / testing).
 *
 * Never in production (plan 15 A1): it throws there. Production runs
 * `php artisan db:seed --class=LaunchSeeder --force` instead, and there is
 * no other staff command (Users screen: P4).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * @throws RuntimeException in production
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DatabaseSeeder must not run in production: use `php artisan db:seed --class=LaunchSeeder --force`.');
        }

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

        // Demo content: each seeder checks its environment itself (local / staging only).
        if (app()->environment(['local', 'staging'])) {
            $this->call([
                ProductsSeeder::class,
                DemoLeadsSeeder::class,
                DemoNewsSeeder::class,
                DemoBannersSeeder::class,
            ]);
        }

        // First Admin from config admin.seed_login / seed_password, when set (local and staging).
        if (filled(config('admin.seed_login')) && app()->environment(['local', 'staging'])) {
            $this->call(FirstAdminSeeder::class);
        }

        // A non-staff test user (local and testing; it can't open the admin panel).
        if (app()->environment(['local', 'testing'])) {
            User::query()->firstOrCreate(
                ['email' => 'test@example.com'],
                ['name' => 'Test User', 'password' => Str::random(40)],
            );
        }
    }
}
