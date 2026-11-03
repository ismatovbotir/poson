<?php

return [
    'title' => 'Monitoring',
    'subtitle' => 'Scheduler, queue worker, scheduled tasks and failed jobs.',
    'close' => 'Close',
    'confirm_required' => 'To run tasks or change failed jobs, confirm your password (valid for :minutes minutes).',
    'confirm_button' => 'Confirm password',

    'retry_after_warning' => 'Queue retry_after is :retry_after s, which is not above the “Run now” job timeout (:timeout s): a long task could be started twice. Set DB_QUEUE_RETRY_AFTER=660 on the server.',

    'units' => ['ms' => 'ms', 'min' => 'min'],

    'telegram_problem_open' => 'Open the bot page',

    'cards' => [
        'cron' => 'Scheduler (cron)',
        'worker' => 'Queue worker',
        'queue' => 'Queue',
        'alerts' => 'Alerts',
        'never' => 'No signal',
        'down' => 'Not responding',
        'ok' => 'Running',
        'last_tick' => 'Last signal',
        'lag' => 'Queue lag',
        'pending' => 'Waiting',
        'oldest' => 'Oldest waiting',
        'failed' => 'Failed',
        'ping' => 'External check',
        'configured' => 'Configured',
        'not_configured' => 'Not set',
        'set_up' => 'Set up in Integrations',
    ],

    'tasks' => [
        'title' => 'Scheduled tasks',
        'hint' => 'Last run of every task from the schedule. Times are Tashkent time.',
        'empty' => 'No schedule snapshot yet. It appears after the first monitoring check.',
        'no_snapshot' => 'No schedule snapshot yet: the scheduler (cron) has not run the monitor.',
        'stale' => 'The schedule snapshot is old (:time). Check that cron is running.',
        'task' => 'Task',
        'last_run' => 'Last run',
        'status' => 'Status',
        'duration' => 'Duration',
        'next_run' => 'Next run',
        'actions' => 'Actions',
        'run_now' => 'Run now',
        'show_runs' => 'History',
        'hide_runs' => 'Hide',
        'last_runs' => 'Last :count runs',
    ],

    'failed' => [
        'title' => 'Failed jobs',
        'hint' => 'Only the job name and a short error are shown, never the job data.',
        'empty' => 'No failed jobs.',
        'job' => 'Job',
        'queue' => 'Queue',
        'failed_at' => 'Failed',
        'error' => 'Error',
        'select' => 'Select job',
        'details' => 'Details',
        'retry' => 'Retry',
        'forget' => 'Delete',
        'retry_selected' => 'Retry selected',
        'forget_selected' => 'Delete selected',
        'stale_warning' => 'Older than :hours h: the message may no longer be relevant.',
        'detail_title' => 'Failed job',
        'exception' => 'Error type',
        'message' => 'Message',
        'frame' => 'Where',
    ],

    'job_labels' => [
        'send_telegram_message' => 'Telegram message',
        'send_telegram_alerts' => 'Telegram alerts',
        'send_lead_to_telegram' => 'New lead alert',
        'notify_finance_of_price_sync_issues' => 'Price sync alert',
    ],

    'confirm' => [
        'run_title' => 'Run the task now?',
        'run_body' => 'The task :task will be queued and run once, outside its schedule.',
        'retry_title' => 'Retry selected jobs?',
        'retry_body' => 'The jobs go back to the queue. Old Telegram messages may arrive late.',
        'forget_title' => 'Delete failed jobs?',
        'forget_body' => 'The failed jobs are removed for good.',
    ],

    'toasts' => [
        'run_queued' => 'Task queued',
        'run_queued_body' => 'It runs on the monitoring queue; see the result in the table.',
        'retried' => ':count job(s) sent back to the queue',
        'forgotten' => ':count failed job(s) deleted',
    ],

    'errors' => [
        'invalid' => 'This request is not allowed.',
        'queued' => 'This task is already queued or running.',
        'running' => 'The task is already running.',
        'cooldown' => 'The task was started recently. Try again in a few minutes.',
        'rate' => 'Too many actions. Try again later.',
        'too_many' => 'Too many jobs selected (at most 50).',
        'class' => 'These jobs can only be deleted, not retried.',
    ],
];
