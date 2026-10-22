<?php

namespace App\Http\Middleware;

use App\Support\Analytics\BotRegistry;
use App\Support\Analytics\TrafficRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * First-party traffic tracking (plan 04 S12), a global middleware so that
 * 404s for unknown URLs are seen too.
 *
 * handle(): decides whether the request is tracked (GET, not a prefetch, not
 * /admin, Livewire, webhooks, API or tooling) and whether the User-Agent is
 * a crawler. Crawlers get the in-memory "array" session driver for this
 * request (no session row) and no cookies at all (session, XSRF, locale).
 *
 * terminate(): after the response is sent, writes one raw row: bot_hits for
 * crawlers (never site_visits), site_visits for people, using what
 * CaptureAttribution put on the request (channel, entry, staff skip).
 * People opening robots.txt / sitemap.xml / llms.txt are not page views.
 * Any failure there is reported (throttled) and swallowed.
 * Nothing heavier than a regex runs before the response.
 */
class TrackVisit
{
    public function __construct(protected TrafficRecorder $recorder) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('analytics.enabled') || (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) || $this->isExcluded($request)) {
            return $next($request);
        }

        $bot = BotRegistry::match($request->userAgent());

        $request->attributes->set(TrafficRecorder::ATTR_TRACKED, $request->isMethod('GET') && ! $this->isPrefetch($request));
        $request->attributes->set(TrafficRecorder::ATTR_BOT, $bot);

        if ($bot === null) {
            return $next($request);
        }

        // Crawlers: keep the session in memory only for this request.
        $driver = config('session.driver');
        config(['session.driver' => 'array']);

        try {
            $response = $next($request);
        } finally {
            config(['session.driver' => $driver]);
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($request->attributes->get(TrafficRecorder::ATTR_TRACKED) !== true || $request->attributes->get(TrafficRecorder::ATTR_SKIP) === true) {
                return;
            }

            $bot = $request->attributes->get(TrafficRecorder::ATTR_BOT);

            if ($bot !== null) {
                $this->recorder->recordBotHit($request, $response, $bot);

                return;
            }

            if ($request->is(...(array) config('analytics.bot_only_paths', []))) {
                return;
            }

            $visit = $request->attributes->get(TrafficRecorder::ATTR_VISIT);

            $this->recorder->recordPageView($request, $response, is_array($visit) ? $visit : null);
        } catch (Throwable $exception) {
            TrafficRecorder::reportThrottled($exception);
        }
    }

    protected function isExcluded(Request $request): bool
    {
        return $request->is(...(array) config('analytics.exclude_paths', []));
    }

    /**
     * Speculative loads (browser prefetch / prerender) are not visits.
     */
    protected function isPrefetch(Request $request): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Purpose', 'X-Moz'] as $header) {
            $value = strtolower((string) $request->headers->get($header));

            if (str_contains($value, 'prefetch') || str_contains($value, 'prerender') || str_contains($value, 'preview')) {
                return true;
            }
        }

        return false;
    }
}
