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

    // Text provider (plan 10): anthropic | openrouter.
    'text_provider' => env('AI_TEXT_PROVIDER'),

    'text_model' => env('AI_TEXT_MODEL'),

    // OpenRouter model ID ("provider/model", free text with suggestions) and
    // whether providers that log or train on prompts may be used.
    'openrouter_model' => env('AI_OPENROUTER_MODEL'),

    'openrouter_allow_data_collection' => env('AI_OPENROUTER_ALLOW_DATA_COLLECTION'),

    'vision_model' => env('AI_VISION_MODEL'),

    'image_model' => env('AI_IMAGE_MODEL'),

    'limits' => [
        'daily_text' => env('AI_DAILY_LIMIT_PER_USER'),
        'daily_image' => env('AI_DAILY_IMAGE_LIMIT_PER_USER'),
        // Whole US dollars per calendar month (Tashkent time); AI is disabled once reached. Null = no cap.
        'monthly_budget_usd' => env('AI_MONTHLY_BUDGET_USD'),
    ],

    // Seconds per provider request (not editable in admin). The RunAiRequest job
    // may run 2 × timeout + 15 s, so queue.retry_after must be larger than that.
    'timeout' => (int) (env('AI_TIMEOUT') ?: 45),

    'image_timeout' => (int) (env('AI_IMAGE_TIMEOUT') ?: 120),

    // Queue of the RunAiRequest job: run `php artisan queue:work --queue=ai,default`.
    'queue' => 'ai',

    // Quotas, "today" and "this month" are counted in this timezone.
    'timezone' => 'Asia/Tashkent',

    // Upper bound of output tokens per text request (three locales of one field).
    'max_output_tokens' => 8192,

    // Input tokens assumed for an open request in the budget check (system prompt + hint + context).
    'input_tokens_estimate' => 6000,

    // Seconds to wait for the quota lock before refusing with "rate_limited".
    'lock_wait' => 5,

    // Prompt and response texts of the request log are cleared after this many days (ai:prune-requests).
    'retention_days' => 90,

    /*
    | Allow-lists for the model selects in admin (the first entry is the
    | default). Model IDs confirmed against laravel/ai v1.3 (AnthropicProvider
    | defaults). Default: Opus 5.5 (PM decision 2026-10-23).
    |
    | "openrouter" is only the suggestion list of the OpenRouter model input
    | (any valid ID may be typed); the first entry is the default. Every ID
    | was checked against GET https://openrouter.ai/api/v1/models on
    | 2026-10-24 (plan 10). ":free" models cost nothing but are rate-limited
    | and may log prompts.
    */
    'models' => [
        'text' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'],
        'openrouter' => [
            'nvidia/nemotron-3.5-super-vl:free',
            'google/gemma-4-31b-it:free',
            'google/gemini-3.8-flash',
            'openai/gpt-6-luna',
            'qwen/qwen3.8-flash',
        ],
        'vision' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'],
        'image' => ['gpt-image-1'],
    ],

    /*
    | Cost rates in USD per million tokens (= micro-USD per token), used for
    | the request log and the monthly budget (PM decision 2026-10-23).
    | Unknown models use "fallback" (Opus, the most expensive), so the
    | budget errs on the safe side; unknown OpenRouter ":free" models cost 0.
    | OpenRouter requests store the exact usage.cost when the answer has it.
    | OpenRouter rates: GET /api/v1/models pricing, 2026-10-24.
    */
    'rates' => [
        'claude-opus-5-5' => ['input' => 4, 'output' => 20],
        'claude-sonnet-5-5' => ['input' => 2, 'output' => 10],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
        'google/gemini-3.8-flash' => ['input' => 0.825, 'output' => 4.125],
        'openai/gpt-6-luna' => ['input' => 0.10, 'output' => 0.50],
        'qwen/qwen3.8-flash' => ['input' => 0.15, 'output' => 0.47],
        'fallback' => ['input' => 4, 'output' => 20],
    ],

    /*
    | BrandGuard: on top of config('brand.banned_patterns') (which also block
    | saving), these softer phrases only raise a warning in the AI preview.
    */
    'warning_patterns' => [
        '/\baffordabl/u',
        '/\bbest prices?\b/u',
        '/\bbargains?\b/u',
        '/\binexpensive\b/u',
        '/(?<!\pL)выгодн\pL* цен/u',
        '/(?<!\pL)низк\pL* цен/u',
        '/(?<!\pL)доступн\pL* цен/u',
        '/(?<!\pL)hamyonbop/u',
        '/(?<!\pL)qulay narx/u',
        '/(?<!\pL)past narx/u',
    ],

    /*
    | Glossary for the system prompt: en => [ru, uz]. Uzbek Latin uses ʻ (U+02BB)
    | in oʻ / gʻ and ʼ (U+02BC) for the tutuq belgisi.
    */
    'glossary' => [
        'barcode scanner' => ['сканер штрихкода', 'shtrix-kod skaneri'],
        'label' => ['этикетка', 'yorliq'],
        'label printer' => ['принтер этикеток', 'yorliq printeri'],
        'receipt printer' => ['чековый принтер', 'chek printeri'],
        'mobile computer (TSD)' => ['терминал сбора данных (ТСД)', 'maʼlumot yigʻish terminali (TSD)'],
        'ribbon' => ['риббон', 'ribbon'],
        'category' => ['категория', 'toifa'],
        'lead / request' => ['заявка', 'murojaat'],
        'quote (commercial offer)' => ['коммерческое предложение', 'tijorat taklifi'],
        'warranty' => ['гарантия', 'kafolat'],
        'service centre' => ['сервисный центр', 'servis markazi'],
        'repair' => ['ремонт', 'taʼmirlash'],
        'setup and integration' => ['настройка и интеграция', 'sozlash va integratsiya'],
        'support' => ['поддержка', 'qoʻllab-quvvatlash'],
        'AI' => ['ИИ', 'SI (sunʼiy intellekt)'],
    ],

];
