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
    | Foreign (non-+998) phone numbers on the public forms: lead form, cart
    | quote and checkout (plan 14 v2 §20, TG-44 kill switch). false = the
    | public entry points accept Uzbek numbers only; admin CRM and the bot
    | are unaffected.
    */
    'foreign_phones_enabled' => filter_var(env('SITE_FOREIGN_PHONES_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    | TG-43: public submissions with a non-Uzbek phone accepted per hour
    | across lead form, cart quote and checkout (limiter "site-foreign-phone").
    | Over the cap: the same validation error, one management alert per hour.
    */
    'foreign_phone_limit_per_hour' => (int) env('SITE_FOREIGN_PHONE_LIMIT_PER_HOUR', 30),

    /*
    | TG-41: country codes never accepted (non-geographic and premium).
    */
    'reserved_phone_prefixes' => ['800', '808', '870', '878', '881', '882', '883', '888', '979', '991'],

    /*
    | Products per page on catalog listings and search results.
    */
    'per_page' => 24,

    /*
    | News posts per page on the news list (plan 07 7b).
    */
    'news_per_page' => 12,

    /*
    | Minutes /sitemap.xml stays cached (one week; flushed by every catalog,
    | page and news save, or by the admin Refresh button).
    */
    'sitemap_cache_minutes' => 10080,

    /*
    | Render the analytics tag (GA4 / Metrika, Settings → Company → Tracking)
    | outside production too, e.g. on staging to check the IDs. Tracking still
    | has to be enabled, and staff are never tracked.
    */
    'analytics_force' => (bool) env('ANALYTICS_FORCE', false),

];
