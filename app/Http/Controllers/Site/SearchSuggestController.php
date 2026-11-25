<?php

namespace App\Http\Controllers\Site;

use App\Actions\Site\SuggestSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /{locale}/search/suggest?q= (plan 43 #40): the header search dropdown's
 * JSON (SuggestSearch). Throttled by "search-suggest" (quiet 429), never
 * indexed, privately cacheable for a minute. The term is never logged.
 */
class SearchSuggestController extends Controller
{
    public const HEADERS = [
        'X-Robots-Tag' => 'noindex',
        'Cache-Control' => 'private, max-age=60',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __invoke(Request $request, SuggestSearch $suggest): JsonResponse
    {
        // < > & ' " as \u escapes: a name is never markup, even if the JSON is sniffed as HTML.
        return response()->json($suggest->handle($request->query('q')), 200, self::HEADERS, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }
}
