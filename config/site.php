<?php

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
*/

return [

    /*
    | "Add to cart" on product cards and pages (plan 03 D18). Off until the
    | cart and checkout exist: cart / both products show the quote CTA.
    */
    'cart_enabled' => (bool) env('SITE_CART_ENABLED', false),

    /*
    | Products per page on catalog listings and search results.
    */
    'per_page' => 24,

    /*
    | Minutes /sitemap.xml stays cached (flushed on catalog saves).
    */
    'sitemap_cache_minutes' => 60,

    /*
    | Render the analytics tag (GA4 / Metrika, Settings → Company → Tracking)
    | outside production too, e.g. on staging to check the IDs. Tracking still
    | has to be enabled, and staff are never tracked.
    */
    'analytics_force' => (bool) env('ANALYTICS_FORCE', false),

];
