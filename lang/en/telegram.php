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

];
