<?php

namespace Database\Seeders;

use App\Support\Catalog\CategoryTree;
use App\Support\CompanySettings;
use App\Support\Site\BannerDirectory;
use App\Support\Site\LlmsTxt;
use App\Support\Site\NewsDirectory;
use App\Support\Site\PageDirectory;
use App\Support\Site\Sitemap;
use Illuminate\Database\Seeder;

/**
 * The only seeder for production (plan 15 A):
 *
 *   php artisan db:seed --class=LaunchSeeder --force
 *
 * Roles and permissions, the first Admin (FirstAdminSeeder, from
 * ADMIN_SEED_LOGIN / ADMIN_SEED_PASSWORD; an invalid value stops here,
 * before any content), the catalog base data (create-only: existing rows are
 * never changed), the real products as drafts (ProductsSeeder, plan 16:
 * insert-only by SKU), FAQ, starter pages and draft articles, then the demo
 * news and banners (DemoSeeding opt-in for this process only). There are no
 * demo products any more. Never demo leads, customers, quotes or extra
 * users. Every step is idempotent. Run `php artisan demo:remove --force`
 * first when the old demo content is still in the database.
 *
 * Model events stay on (no WithoutModelEvents), and the site caches are
 * flushed at the end anyway. Demo content is removed later with
 * `php artisan demo:remove --force`.
 */
class LaunchSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            FirstAdminSeeder::class,
            CatalogSeeder::class,
            ProductsSeeder::class,
            FaqSeeder::class,
            PagesSeeder::class,
            ArticlesSeeder::class,
        ]);

        DemoSeeding::allowingProduction(fn () => $this->call([
            DemoNewsSeeder::class,
            DemoBannersSeeder::class,
        ]));

        CategoryTree::flush();
        PageDirectory::flushAll();
        NewsDirectory::flushAll();
        BannerDirectory::flush();
        Sitemap::flush();
        LlmsTxt::flush();
        CompanySettings::flush();
    }
}
