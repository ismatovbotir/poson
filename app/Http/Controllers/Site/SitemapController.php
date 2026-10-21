<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\Sitemap;
use Illuminate\Http\Response;

/**
 * GET /sitemap.xml (no locale prefix): cached XML of every public page in uz, ru and en.
 */
class SitemapController extends Controller
{
    public function __invoke(Sitemap $sitemap): Response
    {
        return response($sitemap->xml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
