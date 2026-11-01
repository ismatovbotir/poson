<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\CompanySettings;
use App\Support\Site\LlmsTxt;
use Illuminate\Http\Response;

/**
 * GET /llms.txt (no locale prefix, plan 04 T12): a cached plain-text summary
 * of Poson for AI assistants (App\Support\Site\LlmsTxt). Not for search
 * results, hence X-Robots-Tag: noindex. A 404 while the site is closed for
 * indexing (plan 15 C), checked before the cache.
 */
class LlmsController extends Controller
{
    public function __invoke(LlmsTxt $llms, CompanySettings $settings): Response
    {
        abort_unless($settings->isIndexingOpen(), 404);

        return response($llms->text(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
