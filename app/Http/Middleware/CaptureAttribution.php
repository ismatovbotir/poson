<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Analytics\Attribution;
use App\Support\Analytics\TrafficRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Session part of the traffic tracking (web group, after StartSession), for
 * people on requests TrackVisit tracks:
 *
 *  - signed-in staff are never recorded;
 *  - the first page view of a session stores the first-touch attribution
 *    (channel, source, UTM tags, landing) in the session and is the entry,
 *    unless it came from the site itself;
 *  - every page view is recorded under the session's channel.
 */
class CaptureAttribution
{
    public function __construct(protected Attribution $attribution) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(TrafficRecorder::ATTR_TRACKED) === true
            && $request->attributes->get(TrafficRecorder::ATTR_BOT) === null
            && $request->hasSession()) {
            try {
                $this->capture($request);
            } catch (Throwable $exception) {
                // Analytics never breaks a page: this visit is just not recorded.
                $request->attributes->set(TrafficRecorder::ATTR_SKIP, true);
                TrafficRecorder::reportThrottled($exception);
            }
        }

        return $next($request);
    }

    protected function capture(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User && $user->isStaff()) {
            $request->attributes->set(TrafficRecorder::ATTR_SKIP, true);

            return;
        }

        $stored = $this->attribution->stored($request);
        $isEntry = false;

        if ($stored === null) {
            $isEntry = ! $this->attribution->classify($request)->isInternal();
            $stored = $this->attribution->current($request);
        }

        $request->attributes->set(TrafficRecorder::ATTR_VISIT, [
            'channel' => Attribution::channel($stored)->value,
            'source' => $stored['source'] ?? null,
            'utm_medium' => $stored['utm_medium'] ?? null,
            'utm_campaign' => $stored['utm_campaign'] ?? null,
            'referrer_host' => $stored['referrer_host'] ?? null,
            'is_entry' => $isEntry,
        ]);
    }
}
