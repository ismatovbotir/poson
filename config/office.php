<?php

/*
|--------------------------------------------------------------------------
| Virtual office (plan 41, phase 1: read and analyse, never send)
|--------------------------------------------------------------------------
|
| Telegram Business chats of the owner's account, connected to the customer
| bot. Everything stays off until an Admin switches ingest and AI on in
| Admin → Virtual office → Settings. The values here are the defaults and
| hard bounds; the Admin's stored settings override the defaults.
|
*/

return [

    // Telegram user ids of the business account owner(s) whose connection is trusted (VO-1). A non-empty
    // value here locks the setting on the screen (comma-separated integers). Not a secret.
    'owner_telegram_ids' => env('OFFICE_OWNER_TELEGRAM_IDS'),

    // Minutes of silence before a client chat is analysed (5–60).
    'debounce_minutes' => 10,
    'debounce_min' => 5,
    'debounce_max' => 60,

    // Analyses per client chat and Tashkent day (1–24).
    'per_chat_daily' => 12,
    'per_chat_daily_max' => 24,

    // Manual "Analyse now": cooldown per chat (seconds) and requests per user and day (VO-11).
    'manual_cooldown_seconds' => 60,
    'manual_per_user_daily' => 50,

    // Scheduler: due chats per run and analyses queued or running at once (VO-16).
    'dispatch_batch' => 10,
    'max_pending' => 3,

    // Flood limits (VO-3).
    'limits' => [
        'new_chats_per_minute' => 30,
        'new_chats_per_day' => 200,
        'stored_messages_per_chat_daily' => 500,
        'connection_fetches_per_hour' => 20,
    ],

    // Daily caps per usage channel (Asia/Tashkent day). The Admin may raise them up to cap_factor × these values.
    'caps' => [
        'vo_route' => ['requests' => 400, 'tokens' => 1000000],
        'vo_sales' => ['requests' => 200, 'tokens' => 2000000],
        'vo_serv' => ['requests' => 200, 'tokens' => 2000000],
        'vo_support' => ['requests' => 100, 'tokens' => 1000000],
    ],
    'cap_factor' => 5,

    // Models and output limits per agent role (only AnthropicChatModel::MODELS). Editable per version in Education.
    'agents' => [
        'router' => ['model' => 'claude-haiku-5-5', 'max_tokens' => 300, 'max_tokens_min' => 100, 'max_tokens_max' => 500],
        'sales' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 900, 'max_tokens_min' => 300, 'max_tokens_max' => 1500],
        'service' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 900, 'max_tokens_min' => 300, 'max_tokens_max' => 1500],
        'support' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 900, 'max_tokens_min' => 300, 'max_tokens_max' => 1500],
    ],

    // Seconds: SDK timeouts per call. router + agent stay below the job timeout, which stays below the
    // queue's retry_after (300 on the database queue, VO-11).
    'router_timeout' => 20,
    'agent_timeout' => 60,
    'job_timeout' => 120,
    'queue' => 'ai-admin',

    // Transcript sent to the model (VO-6): newest messages, oldest first, character budget, per-message cut.
    'transcript' => [
        'messages' => 40,
        'chars' => 8000,
        'message_chars' => 1000,
    ],

    // Text limits of an agent version (VO-10).
    'education' => [
        'instructions_max' => 8000,
        'knowledge_max' => 20000,
        'rules_max' => 4000,
        'examples_max' => 10,
        'example_chars' => 1000,
        'change_note_max' => 255,
    ],

    // Output limits (characters) of the validated analysis.
    'output' => [
        'topic' => 120,
        'router_summary' => 300,
        'summary' => 800,
        'next_step' => 300,
        'draft' => 1500,
        'products' => 5,
        'product_chars' => 80,
        'device_brand' => 60,
        'device_model' => 60,
    ],

    // Retention (plan 41 §9, VO-18).
    'retention' => [
        'unmarked_days' => 14,
        'ignored_names_days' => 14,
        'client_months' => 12,
        'analyses_kept' => 5,
        'draft_days' => 30,
        'disallowed_connection_days' => 30,
    ],

    // Seconds an unmark waits for a running analysis of the chat to release its lock (VO-12).
    'lock_wait_seconds' => 5,

    // Transcript page size on the chat screen (VO-16).
    'transcript_page' => 50,
];
