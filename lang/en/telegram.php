<?php

/*
| Telegram messages (plan 05c). Language: Settings → Integrations → Telegram
| (telegram.locale, default uz). Values go into parse_mode=HTML after e().
*/

return [

    'lead' => [
        'title' => 'New lead :reference',
        'channel' => 'Source',
        'products' => 'Products',
        'more_items' => 'and :count more',
        'contact' => 'Client',
        'foreign_phone' => 'Non-UZ number',
        'company' => 'Company',
        'comment' => 'Comment',
        'duplicate' => 'Repeat: lead :reference already exists',
        'customer' => 'CRM customer',
        'open_in_admin' => 'Open in admin',
    ],

    'price_sync' => [
        'title' => 'Price sync problems, batch #:id',
        'token' => 'Token',
        'no_token' => 'deleted token',
        'rejected' => 'The whole batch was rejected: :error',
        'counts' => 'Received :received · updated :updated · unchanged :unchanged · unknown :unknown · invalid :invalid',
        'unknown_codes' => 'Unknown codes',
        'more_codes' => 'and :count more',
        'open_log' => 'Open the batch',
    ],

    'order' => [
        'placed' => 'New order :number',
        'total' => 'Total',
        'payment_method' => 'Payment',
        'delivery' => 'Delivery',
        'delivery_separate' => 'billed separately',
        'products' => 'Products',
        'contact' => 'Client',
        'foreign_phone' => 'Non-UZ number',
        'company' => 'Company',
        'amount' => 'Amount',
        'method' => 'Method',
        'reference' => 'Reference',
        'paid_on' => 'Paid on',
        'by' => 'Recorded by',
        'below_reference' => 'The order has lines priced below the customer’s level price.',
        'open_settings' => 'Open settings',
        'payment' => [
            'paid' => 'Payment received for :number',
            'delivery_paid' => 'Delivery paid for :number',
            'refunded' => 'Refund recorded for :number',
            'voided' => 'Payment voided for :number',
        ],
        'uzqr' => [
            'uploaded' => 'The UzQR payment code was uploaded',
            'replaced' => 'The UzQR payment code was replaced',
            'removed' => 'The UzQR payment code was removed',
            'check' => 'Check that this change was expected.',
        ],
    ],

    'uzqr' => [
        'unmatched' => 'UzQR payment for order :number received but not applied',
        'unmatched_hint' => 'The order was not changed. An Admin must resolve it on the order page.',
        'resolved' => 'Unmatched UzQR payment for order :number resolved',
        'hold_released' => 'UzQR hold released for order :number',
        'hold_released_hint' => 'The order can be paid again. The bank may still take the old QR payment: it will be applied or flagged as unmatched automatically.',
        'problem' => 'UzQR integration problem',
        'settings_changed' => 'UzQR settings changed',
        'settings_check' => 'If you didn’t expect this change, check Settings → Integrations right away.',
        'labels' => [
            'amount' => 'Amount',
            'reason' => 'Reason',
            'resolution' => 'Decision',
            'by' => 'By',
            'order' => 'Order',
            'fields' => 'Fields',
        ],
    ],

    'mcp' => [
        'events' => [
            'granted' => 'MCP access granted',
            'transferred' => 'MCP access transferred',
            'access_revoked' => 'MCP access revoked',
            'token_generated' => 'New MCP token generated',
            'token_regenerated' => 'MCP token regenerated',
            'token_revoked' => 'MCP token revoked',
            'token_settings' => 'MCP token settings changed',
            'first_use' => 'MCP token used for the first time',
            'new_ip' => 'MCP token used from a new IP address',
            'new_client' => 'MCP token used from a new client',
            'revoked_used' => 'Someone used a revoked MCP token',
            'ip_blocked' => 'MCP token used from an address that isn’t allowed',
            'rotation' => 'MCP token is due for rotation',
            'post_published' => 'Claude published a post',
            'post_scheduled' => 'Claude scheduled a post',
            'post_unpublished' => 'A post published by Claude was unpublished',
        ],
        'labels' => [
            'holder' => 'Holder',
            'previous' => 'Previous holder',
            'by' => 'By',
            'label' => 'Device',
            'reason' => 'Reason',
            'tokens' => 'Tokens revoked',
            'ip' => 'IP',
            'client' => 'Client',
            'days' => 'Age, days',
            'title' => 'Title',
            'public_url' => 'Public page',
            'admin_url' => 'Admin page',
        ],
        'hint' => 'If you didn’t expect this, revoke MCP access in Settings → MCP access right away.',
        'post_hint' => 'If you didn’t expect this, unpublish the post in Settings → MCP access → Call log.',
        'open' => 'Open MCP access',
    ],

    'monitoring' => [
        'events' => [
            'cron_dead' => 'Scheduler is not running',
            'task_failed' => 'Scheduled task failed',
            'queue_stuck' => 'Queue is stuck',
            'worker_silent' => 'Queue worker is silent',
            'foreign_phone_cap' => 'Hourly limit of foreign phone numbers on the site reached',
            'telegram_webhook' => 'Telegram bot webhook problem',
            'telegram_token' => 'Telegram refuses the bot token (401)',
            'telegram_customer_cap' => 'Hourly limit of new customers from the Telegram bot reached (registrations stay unlinked)',
        ],
        'recovered' => [
            'cron_dead' => 'Scheduler is running again',
            'task_failed' => 'Scheduled task works again',
            'queue_stuck' => 'Queue is moving again',
            'worker_silent' => 'Queue worker is back',
            'telegram_webhook' => 'Telegram bot webhook works again',
            'telegram_token' => 'Telegram accepts the bot token again',
        ],
        'labels' => [
            'task' => 'Task',
            'at' => 'Time',
            'exit_code' => 'Exit code',
            'error' => 'Error',
            'age' => 'Minutes',
            'pending' => 'Waiting jobs',
            'limit' => 'Limit per hour',
            'issue' => 'Problem',
        ],
        // Plan 14 v2 TG-38: telegram:check-webhook issue names (never the URL or Telegram's error text).
        'webhook_issues' => [
            'url_mismatch' => 'webhook points to another address or is not set',
            'url_unexpected' => 'bot is disabled but the webhook points to an unknown address (token may have leaked)',
            'delivery_errors' => 'delivery errors in the last 15 minutes',
            'backlog' => 'more than 100 updates waiting',
        ],
        'reminder' => 'Still not resolved.',
        'open' => 'Open monitoring',
    ],

    // Plan 14 v2: the bot's command menu (setMyCommands, per language).
    'bot_commands' => [
        'start' => 'Start and register',
        'language' => 'Change language',
        'help' => 'Help and contacts',
    ],
];
