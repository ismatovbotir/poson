<?php

/*
|--------------------------------------------------------------------------
| Dynamic UzQR (plan 11e, bank document "ТИ UzQR API POS 1.2")
|--------------------------------------------------------------------------
|
| The website is one UzQR POS with its own API key. The INN, API keys and
| mode are integration settings (services.uzqr, Settings → Integrations,
| read through App\Support\Secrets). Everything here is ops-only config:
|
| - base URLs come ONLY from .env (security condition 48, no SSRF through
|   admin-editable hosts): https, no user info / query / fragment, not an
|   IP literal. Test mode exists only when the test host is https and not
|   the prod host (condition 34), else the site is forced to prod;
| - go-live gate (conditions 33 and 47): prod mode stays blocked until the
|   bank answered B1 (prod URL + sandbox), B2 (separate website POS/key),
|   B3 (timestamp tolerance ≤ 300 s, replay rejection, IP allow-list, key
|   entropy) and B5 (fiscal receipt obligations) in writing. Ops record
|   the answered items in UZQR_BANK_ANSWERS (e.g. "B1,B2,B3,B5") after
|   adding them to .claude/docs/pm/decisions.md.
|
*/

return [

    'base_urls' => [
        'prod' => env('UZQR_PROD_BASE_URL'),
        'test' => env('UZQR_TEST_BASE_URL'),
    ],

    'go_live' => [
        'required' => ['B1', 'B2', 'B3', 'B5'],
        'answered' => array_values(array_filter(array_map(
            fn (string $item): string => strtoupper(trim($item)),
            explode(',', (string) env('UZQR_BANK_ANSWERS', '')),
        ))),
    ],

    // Seconds. Interactive = Livewire modal / admin; background = uzqr:sync.
    'timeouts' => [
        'interactive_connect' => 3,
        'interactive' => 5,
        'background_connect' => 5,
        'background' => 20,
    ],

    // Lifetime of an invoice at the bank is 24 h; we stop offering it 2 min earlier.
    'invoice_minutes' => 24 * 60 - 2,

    // A "creating" row without invoice_id after this many seconds becomes create_failed.
    'creating_timeout_seconds' => 120,

    // The modal polls (wire:poll.3s) for this many minutes, then shows a button.
    'auto_poll_minutes' => 15,

    // Most rows uzqr:sync checks per run.
    'sweep_batch' => 100,

    // Abuse limits (condition 43 / 44).
    'limits' => [
        'open_per_ip_minute' => 10,
        'refresh_per_ip_minute' => 40,
        'refresh_per_order_minute' => 60,
        'per_order_day' => 5,
        'per_order_hour' => 3,
        'new_qr_per_order_hour' => 3,
        'upstream_per_second' => 5,
        'admin_check_per_minute' => 6,
    ],

    // A released hold (Admin "Release hold") is re-checked daily for this many days (condition 61).
    'released_check_days' => 30,

    // Minutes an auth failure (1001 / 1002) hides the method (condition 53).
    'auth_failed_minutes' => 15,

];
