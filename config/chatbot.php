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

];
