<?php

namespace App\Actions\Analytics;

use App\Models\Product;
use App\Models\User;
use App\Support\Analytics\Attribution;
use App\Support\Analytics\TrafficRecorder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Record a "Try" click (the /{locale}/go/{slug} partner redirect) as a
 * site_visits row of type partner_click, under the session's first-touch
 * attribution, so the Analytics screen can show partner clicks per product
 * and channel. Not counted: staff, crawlers (TrackVisit records those as
 * bot hits), prefetches and requests TrackVisit doesn't track. Respects
 * the raw-row growth guard. Never throws: the redirect must not fail
 * because of analytics.
 *
 * The request is then marked as handled (ATTR_SKIP), so TrackVisit doesn't
 * add a page view for the redirect.
 */
class RecordPartnerClick
{
    public function __construct(protected TrafficRecorder $recorder, protected Attribution $attribution) {}

    public function handle(Request $request, Product $product): void
    {
        try {
            if (! config('analytics.enabled')
                || $request->attributes->get(TrafficRecorder::ATTR_TRACKED) !== true
                || $request->attributes->get(TrafficRecorder::ATTR_BOT) !== null
                || $request->attributes->get(TrafficRecorder::ATTR_SKIP) === true) {
                return;
            }

            $request->attributes->set(TrafficRecorder::ATTR_SKIP, true);

            $user = $request->user();

            if ($user instanceof User && $user->isStaff()) {
                return;
            }

            $this->recorder->recordPartnerClick(
                $request,
                Attribution::sanitize($this->attribution->current($request)),
                '/'.ltrim($request->path(), '/'),
                app()->getLocale(),
                $product->id,
            );
        } catch (Throwable $exception) {
            TrafficRecorder::reportThrottled($exception);
        }
    }
}
