<?php

/*
|--------------------------------------------------------------------------
| First-party traffic analytics (plan 04 4c)
|--------------------------------------------------------------------------
|
| TrackVisit writes raw rows (site_visits for people, bot_hits for crawlers);
| AggregateTraffic rolls them up into traffic_daily(_pages) for the admin
| Analytics and AI visibility screens. No cookies, no raw IPs for people.
| Crawlers are listed in config/ai-bots.php.
|
*/

return [

    /*
    | Master switch for recording (the dashboards keep working on old data).
    */
    'enabled' => in_array(env('ANALYTICS_TRACKING'), [null, ''], true) || (bool) env('ANALYTICS_TRACKING'),

    /*
    | Days are counted in the business timezone (the app itself runs in UTC).
    */
    'timezone' => 'Asia/Tashkent',

    /*
    | Growth guard: raw rows inserted per day at most, separately for people
    | (site_visits) and crawlers (bot_hits), so a bot flood can't stop human
    | analytics. Past a cap, that kind of row is dropped for the rest of the
    | day and one warning is logged.
    */
    'max_raw_per_day' => [
        'visits' => (int) (env('ANALYTICS_MAX_VISITS_PER_DAY') ?: 200000),
        'bots' => (int) (env('ANALYTICS_MAX_BOT_HITS_PER_DAY') ?: 150000),
    ],

    /*
    | Cache store of the daily visitor-hash salt (null = the default store).
    | Use a shared, non-persistent store (Redis) in production: on database or
    | file stores the salt sits on disk while it is valid. "cache:clear"
    | rotates it (visitors are counted again from then on that day).
    */
    'salt_store' => env('ANALYTICS_SALT_STORE') ?: null,

    /*
    | Retention. Raw rows in days, aggregates in months.
    */
    'retention' => [
        'site_visits_days' => 30,
        'bot_hits_days' => 14,
        'aggregates_months' => 25,
    ],

    /*
    | Pages kept per date and kind (and per bot) in traffic_daily_pages.
    */
    'top_pages' => 500,

    /*
    | Seconds an aggregation waits for another run of the same day to finish
    | before skipping.
    */
    'aggregate_lock_wait' => 5,

    /*
    | Seconds the dashboard report queries are cached.
    */
    'report_cache_seconds' => 300,

    /*
    | Crawler verification: DNS lookups per day at most, how long a verdict
    | per IP is cached, and where the published IP ranges are stored.
    */
    'verification' => [
        'max_lookups_per_day' => 500,
        'cache_days' => 7,
        'ranges_path' => storage_path('app/bot-ranges.json'),
        // The nightly verification downloads the ranges first when the file is missing or older than this.
        'ranges_max_age_days' => 8,
        // Nightly: these last days are verified (undecided IPs retried) and re-aggregated.
        'days' => 3,
        'http_timeout' => 10,
        'max_redirects' => 3,
        'max_body_bytes' => 2 * 1024 * 1024,
        // Published ranges broader than this are ignored (a broken or hostile list can't verify everyone).
        'min_prefix' => ['ipv4' => 8, 'ipv6' => 16],
    ],

    /*
    | Never recorded: admin panel, Livewire endpoints and assets, webhooks,
    | API, health check and dev tooling (Request::is() patterns).
    */
    'exclude_paths' => [
        'admin', 'admin/*',
        'livewire*', 'livewire*/*',
        'webhooks/*', 'api/*',
        'up', '_*',
    ],

    /*
    | Crawler files: crawler requests are recorded, people opening them are
    | not page views.
    */
    'bot_only_paths' => ['robots.txt', 'sitemap.xml', 'llms.txt'],

    /*
    | Referrer hosts per channel. A host matches itself and its subdomains.
    | "google.*" style entries match any top-level domain (google.co.uz).
    | AI hosts are checked first, so gemini.google.com is AI, not search.
    */
    'hosts' => [
        'ai' => [
            'chatgpt.com', 'chat.openai.com', 'perplexity.ai', 'copilot.microsoft.com',
            'gemini.google.com', 'claude.ai', 'chat.deepseek.com', 'grok.com',
        ],
        'search' => [
            'google.*', 'yandex.*', 'ya.ru', 'bing.com', 'duckduckgo.com',
        ],
        'social' => [
            't.me', 'telegram.org', 'instagram.com', 'facebook.com', 'fb.com',
            'youtube.com', 'youtu.be', 'linkedin.com', 'lnkd.in',
        ],
    ],

];
