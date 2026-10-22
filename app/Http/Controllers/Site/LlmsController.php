<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\LlmsTxt;
use Illuminate\Http\Response;

/**
 * GET /llms.txt (no locale prefix, plan 04 T12): a cached plain-text summary
 * of Poson for AI assistants (App\Support\Site\LlmsTxt). Not for search
 * results, hence X-Robots-Tag: noindex.
 */
class LlmsController extends Controller
{
    public function __invoke(LlmsTxt $llms): Response
    {
        return response($llms->text(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Robots-Tag' => 'noindex',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
