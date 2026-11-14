<?php

/*
|--------------------------------------------------------------------------
| Rule-based chat bot (plan 28) with optional AI answers (plan 30)
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

    // AI answers (plan 30, Claude Haiku via App\Services\Ai only). Off unless an Admin switches it
    // on in Chat bot → Settings and ANTHROPIC_API_KEY is set. Model and limits are fixed here (read only in admin).
    'ai' => [
        'model' => 'claude-haiku-5-5',
        'max_tokens' => 400,
        // Seconds: whole request / connection (AI-5). The SDK never retries (maxRetries 0).
        'timeout' => 12,
        'connect_timeout' => 3,
        // A rule hit with at least this score (or an exact keyword) answers directly, without AI (§2.2).
        'direct_min_score' => 30,
        // History window (phase B): last turns, minutes, characters.
        'history' => [
            'turns' => 10,
            'minutes' => 30,
            'chars' => 4000,
        ],
        // Rate limits, hit in the webhook before the turn is stored (AI-19, P3).
        'limits' => [
            'per_minute_user' => 6,
            'per_day_user' => 40,
            // Customers with an anonymous +888 number or no phone.
            'per_day_anonymous' => 10,
            'per_minute_global' => 60,
            // AI jobs waiting in the queue at once (AI-17): over it the rule reply is used.
            'pending_jobs' => 30,
        ],
        // Daily caps (Asia/Tashkent day, enforced in the database, AI-18). Admins can change them in settings.
        'caps' => [
            'requests' => 1500,
            'tokens' => 3000000,
            'requests_max' => 20000,
            'tokens_max' => 50000000,
        ],
        // USD per million tokens, for the estimate in Stats (cache read billed at 0.1x input).
        'prices' => [
            'input' => 0.10,
            'output' => 0.50,
            'cache_read' => 0.01,
            'cache_write' => 0.125,
        ],
        // Text caps: the customer message sent and stored, the answer, the admin instructions.
        'message_max' => 1000,
        'answer_max' => 1000,
        'instructions_max' => 2000,
        // Static knowledge block (characters, about 6k tokens) and its cache lifetime.
        'knowledge_max' => 24000,
        'knowledge_ttl' => 3600,
        // Words ignored by product retrieval (phase B).
        'stopwords' => [
            'uz' => ['va', 'bilan', 'uchun', 'bormi', 'kerak', 'qancha', 'narxi', 'narx', 'qanday', 'menga', 'sizda', 'bor'],
            'ru' => ['и', 'в', 'на', 'для', 'с', 'есть', 'нужен', 'нужна', 'сколько', 'стоит', 'цена', 'какой', 'мне', 'у', 'вас'],
            'en' => ['the', 'and', 'for', 'with', 'have', 'need', 'how', 'much', 'price', 'what', 'you', 'is', 'a', 'an'],
        ],
        // Days AI turns are kept (chat:prune-ai, phase B).
        'retention_days' => 30,
    ],

];
