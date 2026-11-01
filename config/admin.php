<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dev login
    |--------------------------------------------------------------------------
    |
    | LOCAL ONLY. When true and APP_ENV=local, the admin login accepts any
    | email and password (see AuthenticateStaff::devLoginEnabled()). In any
    | other environment it is ignored, even when set.
    |
    */

    'dev_login' => (bool) env('ADMIN_DEV_LOGIN', false),

    /*
    |--------------------------------------------------------------------------
    | Password re-confirmation
    |--------------------------------------------------------------------------
    |
    | Seconds a password confirmation (/admin/confirm-password) stays valid
    | for sensitive screens such as Settings → Integrations (plan 04 S7).
    |
    */

    'password_confirm_timeout' => 900,

    /*
    |--------------------------------------------------------------------------
    | First admin (launch seed)
    |--------------------------------------------------------------------------
    |
    | Read only by Database\Seeders\FirstAdminSeeder (LaunchSeeder, plan 15 B):
    | the login email and password (at least 12 characters) of the first Admin
    | account. If the email already exists nothing is changed. Remove both
    | from .env after the first seed, then re-cache the config.
    |
    */

    'seed_login' => env('ADMIN_SEED_LOGIN'),

    'seed_password' => env('ADMIN_SEED_PASSWORD'),

];
