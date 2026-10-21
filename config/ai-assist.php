<?php

/*
|--------------------------------------------------------------------------
| Admin AI assist (plan 02)
|--------------------------------------------------------------------------
|
| The values without defaults below are integration fields: when the .env
| value is empty, App\Support\Secrets overlays the value saved in Settings →
| Integrations → AI, else the IntegrationRegistry default (AI off, first
| model of each list, 100 text / 10 image requests per user per day, no
| monthly budget). A non-empty .env value wins and locks the field.
|
*/

return [

    'enabled' => env('AI_ENABLED'),

    'text_model' => env('AI_TEXT_MODEL'),

    'vision_model' => env('AI_VISION_MODEL'),

    'image_model' => env('AI_IMAGE_MODEL'),

    'limits' => [
        'daily_text' => env('AI_DAILY_LIMIT_PER_USER'),
        'daily_image' => env('AI_DAILY_IMAGE_LIMIT_PER_USER'),
        // Whole US dollars per calendar month; AI is disabled once reached. Null = no cap.
        'monthly_budget_usd' => env('AI_MONTHLY_BUDGET_USD'),
    ],

    // Seconds per provider request (not editable in admin).
    'timeout' => (int) (env('AI_TIMEOUT') ?: 45),

    'image_timeout' => (int) (env('AI_IMAGE_TIMEOUT') ?: 120),

    /*
    | Allow-lists for the model selects in admin (the first entry is the
    | default). TODO(plan 02 A1): confirm the exact model IDs against the
    | providers' model lists when laravel/ai is installed.
    */
    'models' => [
        'text' => ['claude-sonnet-4-5', 'claude-haiku-4-5', 'gpt-4.1-mini'],
        'vision' => ['claude-sonnet-4-5', 'gpt-4.1-mini'],
        'image' => ['gpt-image-1'],
    ],

];
