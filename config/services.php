<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Poson integrations (plan 04b)
    |--------------------------------------------------------------------------
    |
    | Deliberately env() WITHOUT defaults: an empty value means "not set in
    | .env", so App\Support\Secrets overlays the value saved in Settings →
    | Integrations (or the registry default). A non-empty .env value always
    | wins and locks the field in admin. Read these through config() or
    | Secrets::get(), never env(). Defaults live in IntegrationRegistry.
    |
    */

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'chats' => [
            'sales' => env('TELEGRAM_SALES_CHAT_ID'),
            'service' => env('TELEGRAM_SERVICE_CHAT_ID'),
            'finance' => env('TELEGRAM_FINANCE_CHAT_ID'),
        ],
        'notify_new_leads' => env('TELEGRAM_NOTIFY_NEW_LEADS'),
        'notify_price_sync' => env('TELEGRAM_NOTIFY_PRICE_SYNC'),
        'locale' => env('TELEGRAM_LOCALE'),
    ],

    'payme' => [
        'merchant_id' => env('PAYME_MERCHANT_ID'),
        'key' => env('PAYME_KEY'),
        'test_key' => env('PAYME_TEST_KEY'),
        'mode' => env('PAYME_MODE'),
    ],

    'click' => [
        'service_id' => env('CLICK_SERVICE_ID'),
        'merchant_id' => env('CLICK_MERCHANT_ID'),
        'merchant_user_id' => env('CLICK_MERCHANT_USER_ID'),
        'secret_key' => env('CLICK_SECRET_KEY'),
        'test_service_id' => env('CLICK_TEST_SERVICE_ID'),
        'test_merchant_user_id' => env('CLICK_TEST_MERCHANT_USER_ID'),
        'test_secret_key' => env('CLICK_TEST_SECRET_KEY'),
        'mode' => env('CLICK_MODE'),
    ],

    // Dynamic UzQR (plan 11e): merchant STIR / PINFL, API keys of the website
    // POS (live and test) and the active mode. Base URLs: config/uzqr.php.
    'uzqr' => [
        'inn' => env('UZQR_INN'),
        'api_key' => env('UZQR_API_KEY'),
        'test_api_key' => env('UZQR_TEST_API_KEY'),
        'mode' => env('UZQR_MODE'),
    ],

];
