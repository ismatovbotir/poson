<?php

/*
|--------------------------------------------------------------------------
| laravel/ai (plan 02)
|--------------------------------------------------------------------------
|
| Only the providers Poson uses. The keys are integration secrets: when the
| .env value is empty, App\Support\Secrets writes the key saved in Settings
| → Integrations to "ai.providers.<provider>.key" (IntegrationRegistry).
| Code never calls laravel/ai directly; it goes through the contracts in
| App\Services\Ai (TextAssistant, ...).
|
*/

return [

    'default' => 'anthropic',
    'default_for_images' => 'openai',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'openai',
    'default_for_classification' => 'anthropic',

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
            // Structured output via output_config.format (json_schema), not a forced tool call.
            'use_native_structured_output' => true,
            // No beta features: the empty value drops the anthropic-beta header.
            'anthropic_beta' => '',
        ],

        // Phase 2 (image generation).
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => false,
        ],
    ],

];
