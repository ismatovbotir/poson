<?php

namespace App\Actions\Analytics;

use App\Models\User;
use App\Support\Analytics\Attribution;
use App\Support\Analytics\TrafficRecorder;
use Illuminate\Http\Request;
use Throwable;

/**
 * Record an "Add to cart" (plan 11 v2 §1.13) as a site_visits row of type
 * add_to_cart under the session's first-touch attribution: the product id
 * and the page it was added on, never a quantity, price, cart token or
 * anything personal. Staff are not counted. Respects the raw-row growth
 * guard. Never throws: adding to the cart must not fail because of
 * analytics.
 */
class RecordAddToCart
{
    public function __construct(protected TrafficRecorder $recorder, protected Attribution $attribution) {}

    /**
     * @param  string|null  $pageUrl  The page the product was added on
     */
    public function handle(Request $request, int $productId, ?string $pageUrl, ?string $locale): void
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

            $this->recorder->recordAddToCart(
                $request,
                Attribution::sanitize($this->attribution->current($request)),
                is_string($path) && $path !== '' ? $path : '/',
                $locale,
                $productId,
            );
        } catch (Throwable $exception) {
            TrafficRecorder::reportThrottled($exception);
        }
    }
}
