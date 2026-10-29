<?php

namespace App\Actions\Analytics;

use App\Models\User;
use App\Support\Analytics\Attribution;
use App\Support\Analytics\TrafficRecorder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Record a placed order (plan 11 v2 §1.13) as a site_visits row of type
 * order under the session's first-touch attribution: the checkout path and
 * locale only, never the amount, order number, products or anything
 * personal (revenue comes from orders.paid_at + traffic_channel). Staff
 * are not counted. Never throws: placing an order must not fail because of
 * analytics.
 */
class RecordOrderPlaced
{
    public function __construct(protected TrafficRecorder $recorder, protected Attribution $attribution) {}

    public function handle(Request $request, ?string $pageUrl, ?string $locale): void
    {
        try {
            if (! config('analytics.enabled')) {
                return;
            }

            $user = $request->user();

            if ($user instanceof User && $user->isStaff()) {
                return;
            }

            $path = parse_url((string) $pageUrl, PHP_URL_PATH);

            $this->recorder->recordOrderPlaced(
                $request,
                Attribution::sanitize($this->attribution->current($request)),
                is_string($path) && $path !== '' ? $path : '/',
                $locale,
            );
        } catch (Throwable $exception) {
            TrafficRecorder::reportThrottled($exception);
        }
    }
}
