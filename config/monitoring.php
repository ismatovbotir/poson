<?php

use App\Jobs\ForwardTelegramMedia;
use App\Jobs\RecordWorkerHeartbeat;
use App\Jobs\SendTelegramMessage;
use App\Listeners\NotifyFinanceOfPriceSyncIssues;
use App\Listeners\SendLeadToTelegram;
use App\Listeners\SendTelegramAlerts;
use App\Listeners\SendTelegramInboxAlert;

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
        // The worker started from the scheduler (routes/console.php) runs every minute for ~40 s.
        'queue-worker',
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
        SendTelegramMessage::class,
        SendTelegramAlerts::class,
        SendTelegramInboxAlert::class,
        SendLeadToTelegram::class,
        NotifyFinanceOfPriceSyncIssues::class,
        // Plan 14 v2 D13: customer files to the inbox chat (DeliverBotMessage deliberately not).
        ForwardTelegramMedia::class,
    ],

    /*
    | Queue of the "Run now" job (MON-S2). The worker must listen to it:
    | `queue:work --queue=default,monitoring,ai-admin,knowledge` (the scheduler starts it, see WorkerQueues) (ai-admin: the AI product wizard stages, plan 32).
    */

    'queue' => 'monitoring',

    /*
    |--------------------------------------------------------------------------
    | Project size limits in GB (plan 37)
    |--------------------------------------------------------------------------
    | Defaults of Admin → Monitoring → Project size. An Admin edits them there
    | (company setting "monitoring_disk"); a bad stored value falls back to
    | these. Telegram alert when a finished measurement is at or above them.
    */

    'disk' => [
        'warn_gb' => 5,
        'critical_gb' => 8,
    ],

];
