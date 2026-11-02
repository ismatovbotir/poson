<?php

use App\Jobs\RecordWorkerHeartbeat;

return [

    /*
    |--------------------------------------------------------------------------
    | Tasks an Admin may start from Monitoring → "Run now" (plan 17, MON-S1)
    |--------------------------------------------------------------------------
    | Exact task keys (command without php/artisan, see MonitoredTasks::key()).
    | Blocked on purpose: payment tasks (uzqr:sync, orders:release-holds), all
    | model:prune lines (irreversible) and mcp:revoke-idle (duplicate reminders).
    */

    'manual_tasks' => [
        'analytics:aggregate',
        'analytics:verify-bots',
        'analytics:refresh-bot-ranges',
        'quotes:expire',
        'mcp:sweep',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tasks that are not recorded in scheduled_task_runs
    |--------------------------------------------------------------------------
    | monitoring:check is the monitor itself; the heartbeat job only dispatches
    | (the worker heartbeat is its own signal).
    */

    'unrecorded_tasks' => [
        'monitoring:check',
        RecordWorkerHeartbeat::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosts the external ping URL may point to (plan 17, MON-S6)
    |--------------------------------------------------------------------------
    | Developer-only: not editable in admin. "*.x" matches subdomains of x.
    */

    'ping_hosts' => [
        'hc-ping.com',
        '*.healthchecks.io',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed jobs that may be retried from Monitoring (plan 17, MON-S4)
    |--------------------------------------------------------------------------
    | By displayName. Any other class can only be forgotten (retrying could
    | repeat a side effect such as a payment step).
    */

    'retry_classes' => [
        \App\Jobs\SendTelegramMessage::class,
        \App\Listeners\SendTelegramAlerts::class,
        \App\Listeners\SendLeadToTelegram::class,
        \App\Listeners\NotifyFinanceOfPriceSyncIssues::class,
    ],

    /*
    | Queue of the "Run now" job (MON-S2). The worker must listen to it:
    | `queue:work --queue=default,monitoring`.
    */

    'queue' => 'monitoring',

];
