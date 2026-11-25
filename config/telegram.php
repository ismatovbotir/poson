<?php

/*
|--------------------------------------------------------------------------
| Telegram Mini App (plan 20)
|--------------------------------------------------------------------------
|
| Non-secret settings of the Mini App at /tma. The bot token stays in
| services.telegram (read through App\Support\Secrets). The on/off switch is
| the DB setting "telegram.mini_app_enabled" (MiniAppSwitch, default off).
|
*/

return [

    'mini_app' => [
        // Seconds a signed initData stays valid (auth_date), MA-2.
        'auth_ttl' => (int) env('TELEGRAM_MINI_APP_AUTH_TTL', 3600),

        // Seconds an auth_date may lie in the future (clock skew).
        'future_skew' => 60,

        // The Mini App session: idle and absolute lifetime in minutes (MA-6).
        'idle_minutes' => 120,
        'absolute_minutes' => 720,

        // Payment methods the Mini App checkout may offer (PaymentMethod values; MA-8).
        // Online methods (uzqr_dynamic, payme, click) are refused whatever is listed here.
        'payment_methods' => ['on_receipt', 'bank_transfer'],

        // Hosts a product video may open on (openLink, MA-22).
        'video_hosts' => ['www.youtube.com', 'youtube.com', 'youtu.be', 'm.youtube.com'],
    ],

];
