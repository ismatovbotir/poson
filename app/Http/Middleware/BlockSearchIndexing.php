<?php

namespace App\Http\Middleware;

use App\Support\CompanySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps search engines and AI crawlers out while the site is closed for
 * indexing (plan 15 C, Settings → Indexing; closed by default). Runs on the
 * web group: when closed, every response gets X-Robots-Tag "noindex,
 * nofollow", merged with any directives the response already carries
 * (e.g. the quote / order links keep "noarchive"). When open it adds
 * nothing, so it never weakens a stronger header set by a controller.
 * The <meta name="robots"> twin is printed by x-site.seo-head.
 */
class BlockSearchIndexing
{
    /**
     * Directives every response gets while the site is closed.
     */
    public const DIRECTIVES = ['noindex', 'nofollow'];

    public function __construct(protected CompanySettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->settings->isIndexingOpen()) {
            return $response;
        }

        $response->headers->set('X-Robots-Tag', static::closed($response->headers->get('X-Robots-Tag')));

        return $response;
    }

    /**
     * Robots directives for a closed site: an existing value that already
     * holds noindex and nofollow is kept as it is (e.g. "noindex,nofollow",
     * "noindex, nofollow, noarchive"); anything else is merged (merge()).
     */
    public static function closed(?string $existing): string
    {
        $directives = array_map(fn (string $directive): string => strtolower(trim($directive)), explode(',', (string) $existing));

        if (array_diff(self::DIRECTIVES, $directives) === []) {
            return (string) $existing;
        }

        return static::merge($existing);
    }

    /**
     * The existing directives plus noindex and nofollow, without duplicates:
     * "noindex" → "noindex, nofollow"; "noindex, nofollow, noarchive" stays.
     */
    public static function merge(?string $existing): string
    {
        $directives = array_values(array_filter(array_map(
            fn (string $directive): string => strtolower(trim($directive)),
            explode(',', (string) $existing),
        ), fn (string $directive): bool => $directive !== '' && $directive !== 'all' && $directive !== 'index' && $directive !== 'follow'));

        return implode(', ', array_values(array_unique([...self::DIRECTIVES, ...$directives])));
    }
}
