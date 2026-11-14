<?php

/*
|--------------------------------------------------------------------------
| Careers (plan 31)
|--------------------------------------------------------------------------
|
| Vacancies and job applications. Applications are kept until deleted by hand
| (owner decision 2026-11-14): there is no retention setting. CVs are PDF only.
| The two switches below are the config defaults of the settings
| "careers.applications_enabled" and "careers.cv_enabled" (kill switches).
*/

return [

    /*
    | Phase B: CV upload limits. PDF only; the file is inspected by content.
    */
    'cv_max_kb' => 5120,

    'cv_mimes' => ['pdf'],

    /*
    | Hours an encrypted pending CV copy may wait for the Telegram forward.
    */
    'pending_cv_hours' => 24,

    /*
    | Abuse limits of the application form: per IP and per phone number
    | (attempts, window in seconds).
    */
    'ip_limit' => ['attempts' => 3, 'seconds' => 3600],

    'phone_limit' => ['attempts' => 2, 'seconds' => 86400],

    /*
    | CR-3: site-wide caps. Over a cap the application is still saved, but
    | without the CV (text-only) and one counts-only alert is sent.
    */
    'caps' => [
        'applications_per_hour' => 30,
        'cv_forwards_per_hour' => 20,
        'pending_files' => 100,
        'pending_megabytes' => 300,
    ],

    /*
    | Kill switches (settings keys careers.applications_enabled and
    | careers.cv_enabled override these defaults).
    */
    'applications_enabled' => true,

    'open_applications_enabled' => true,

    'cv_enabled' => true,

    'settings_keys' => [
        'applications_enabled' => 'careers.applications_enabled',
        'open_applications_enabled' => 'careers.open_applications_enabled',
        'cv_enabled' => 'careers.cv_enabled',
    ],

];
