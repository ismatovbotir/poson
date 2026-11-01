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
 * before any content), the catalog base data, FAQ, starter pages and draft
 * articles, then the demo products, news and banners (DemoSeeding opt-in
 * for this process only). Never demo leads, customers, quotes or extra
 * users. Every step is idempotent; in production the demo products are
 * skipped once any product exists and are quote-only (never paid online).
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
            FaqSeeder::class,
            PagesSeeder::class,
            ArticlesSeeder::class,
        ]);

        DemoSeeding::allowingProduction(fn () => $this->call([
            DemoProductsSeeder::class,
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
