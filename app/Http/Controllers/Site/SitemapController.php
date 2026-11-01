<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\CompanySettings;
use App\Support\Site\Sitemap;
use Illuminate\Http\Response;

/**
 * GET /sitemap.xml (no locale prefix): cached XML of every public page in uz, ru and en.
 * A 404 while the site is closed for indexing (plan 15 C), checked before the cache.
 */
class SitemapController extends Controller
{
    public function __invoke(Sitemap $sitemap, CompanySettings $settings): Response
    {
        abort_unless($settings->isIndexingOpen(), 404);

        return response($sitemap->xml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
