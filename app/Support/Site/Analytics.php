<?php

namespace App\Support\Site;

use App\Models\User;
use App\Support\CompanySettings;

/**
 * Whether and what to track on a public page (plan 04 S8). The site layout
 * prints the result as <meta name="poson-analytics" content="{json}">;
 * resources/js/analytics.js (bundled, no inline script) reads it and loads
 * GA4 / Yandex Metrika (Webvisor off).
 *
 * Renders only when: production (or config site.analytics_force), tracking
 * enabled with at least one valid ID, and the visitor is not signed-in
 * staff. The admin layout never includes it.
 */
class Analytics
{
    public function __construct(protected CompanySettings $company) {}

    /**
     * The tag payload, or null when nothing should be tracked.
     *
     * @return array{ga4?: string, metrika?: string}|null
     */
    public function config(): ?array
    {
        if (! app()->isProduction() && ! config('site.analytics_force')) {
            return null;
        }

        $tracking = $this->company->tracking();

        if (! $tracking['enabled']) {
            return null;
        }

        $user = auth()->user();

        if ($user instanceof User && $user->isStaff()) {
            return null;
        }

        $config = array_filter([
            'ga4' => $tracking['ga4'],
            'metrika' => $tracking['metrika'],
        ], fn (string $id): bool => $id !== '');

        return $config === [] ? null : $config;
    }

    /**
     * JSON for the meta tag content (escaped again by Blade when printed).
     */
    public function json(): ?string
    {
        $config = $this->config();

        return $config === null ? null : json_encode($config, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
