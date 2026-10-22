<?php

namespace App\Actions\Analytics;

use App\Models\User;
use App\Support\Analytics\Attribution;
use App\Support\Analytics\TrafficRecorder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Record a lead from the site form as a conversion (site_visits type lead)
 * under the session's first-touch attribution, so the dashboards can show
 * leads and conversion per channel and source (plan 04 4c). Staff testing
 * the form are not counted. Respects the raw-row growth guard. Never
 * throws: a lead submit must not fail because of analytics.
 */
class RecordConversion
{
    public function __construct(protected TrafficRecorder $recorder) {}

    /**
     * @param  array<string, string>  $attribution  From Attribution::current()
     * @param  string|null  $pageUrl  Page the form was on
     */
    public function handle(Request $request, array $attribution, ?string $pageUrl, ?string $locale, ?int $productId = null): void
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

            $this->recorder->recordLead(
                $request,
                Attribution::sanitize($attribution),
                is_string($path) && $path !== '' ? $path : '/',
                $locale,
                $productId,
            );
        } catch (Throwable $exception) {
            TrafficRecorder::reportThrottled($exception);
        }
    }
}
