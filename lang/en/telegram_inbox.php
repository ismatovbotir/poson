<?php

/*
| Staff inbox of the Telegram bot (plan 21 A1): Admin → Telegram → Chats.
*/

return [

    'title' => 'Chats',
    'subtitle' => 'Customer conversations from the Telegram bot',
    'search_placeholder' => 'Name, username, company or phone',
    'unnamed' => 'Unnamed customer',
    'unassigned' => 'Unassigned',
    'open' => 'Open conversation',
    'empty_title' => 'No conversations yet',
    'empty_body' => 'Messages written to the bot by customers will appear here.',
    'no_results' => 'Nothing found',
    'no_results_body' => 'Change the filters or the search.',
    'unread_count' => ':count new',
    'scopes' => [
        'all' => 'All',
        'mine' => 'Mine',
        'unassigned' => 'Unassigned',
        'unread' => 'Unread',
    ],
    'filters' => [
        'scope' => 'Show',
        'status' => 'Status',
        'status_all' => 'Any status',
        'clear' => 'Clear filters',
    ],
    'columns' => [
        'customer' => 'Customer',
        'status' => 'Status',
        'manager' => 'Manager',
        'last_message' => 'Last message',
    ],
    'badges' => [
        'unregistered' => 'Not registered',
        'banned' => 'Banned',
        'blocked' => 'Blocked the bot',
        'stuck' => 'Delivery stuck',
    ],
    'banners' => [
        'banned' => 'This customer is banned. Their messages are not accepted.',
        'blocked' => 'The customer blocked the bot, so messages cannot be delivered.',
        'account_changed' => 'The Telegram account of this customer changed. Check who is writing before sharing details.',
        'unregistered' => 'This visitor has not finished registration in the bot.',
    ],
    'show' => [
        'title' => 'Conversation',
        'subtitle' => 'Chat #:id',
        'back' => 'Back to chats',
        'load_older' => 'Load older messages',
        'no_messages' => 'No messages yet.',
        'from_customer' => 'Customer',
        'from_bot' => 'Bot',
        'forward' => 'Team chat',
    ],
    'panel' => [
        'title' => 'Customer',
        'name' => 'Name',
        'username' => 'Username',
        'phone' => 'Phone',
        'company' => 'Company',
        'inn' => 'TIN',
        'locale' => 'Language',
        'status' => 'Conversation',
        'manager' => 'Manager',
        'registered_at' => 'Registered',
        'last_inbound_at' => 'Last message from customer',
    ],

];
