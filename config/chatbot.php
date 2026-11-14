<?php

/*
|--------------------------------------------------------------------------
| Rule-based chat bot (plan 28, no AI)
|--------------------------------------------------------------------------
|
| Limits and thresholds. Texts, channel switches and working hours are
| settings (Admin → Chat bot), not config.
|
*/

return [

    // Lowest score (sum of matched keyword weights + rule priority) that counts as a match.
    'min_score' => 10,

    // A conversation goes back to the bot after this many hours without staff
    // or customer activity, never while the customer is waiting for staff (CB-4).
    'handoff_ttl_hours' => 8,

    // The website chat widget (C5) is not built yet: the web channel switch cannot be turned on until this is true.
    'web_ready' => false,

    // Input (CB-1): cut to this many bytes before normalizing, to this many characters after.
    'input_max_bytes' => 1000,
    'input_max_chars' => 200,

    // Rule caps (CB-2).
    'max_rules' => 500,
    'max_keywords_per_rule' => 50,
    'max_buttons' => 8,

    // Text caps (CB-5).
    'answer_max' => 1000,
    'label_max' => 40,
    'keyword_max' => 120,

    // Cached compiled rules.
    'cache_lock_seconds' => 10,
    'cache_lock_wait_seconds' => 3,

    // Unmatched phrases (CB-10): per day, over all chats and per customer.
    'unmatched' => [
        'per_day_global' => 1000,
        'per_day_chat' => 20,
    ],

    // Telegram: FAQ questions offered as buttons (at most 10).
    'telegram' => [
        'faq_items' => 6,
    ],

    // Absolute https link hosts allowed besides the site's own host (CB-6).
    'link_hosts' => [],

    // Product search in the chat (C3, CB-14): hits before the query, per Telegram customer and over all chats.
    'search' => [
        'per_minute_user' => 10,
        'per_minute_global' => 120,
        'max_results' => 8,
    ],

    // Order / repair status lookups, Telegram only (C3, CB-15, CB-16): hits before the query.
    // A number with failures_alert failed attempts in 24 h raises one counts-only monitoring alert.
    'lookup' => [
        'per_hour_user' => 5,
        'per_hour_global' => 300,
        'failures_alert' => 5,
        'order_months' => 12,
        'repair_closed_days' => 90,
        'recent' => 5,
    ],

    // Lead dialogs, Telegram (C4, CB-8, CB-17): hit last, after every refusal.
    'leads' => [
        'per_day_user' => 3,
        'per_hour_global' => 30,
        'dialog_minutes' => 30,
        'answer_max' => 500,
        'dialog_max_bytes' => 2048,
    ],

];
