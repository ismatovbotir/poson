<?php

/*
| Staff accounts (plan 24): own password screen, password rule, recovery command, alerts.
*/

return [
    'account' => [
        'menu' => 'Change password',
        'title' => 'Change password',
        'subtitle' => 'Enter your current password, then choose a new one.',
        'forced_notice' => 'You signed in with a temporary password. Choose your own password to continue. The temporary password stops working on :date.',
        'sessions_notice' => 'This browser stays signed in. Every other device will be signed out.',
        'mcp_warning' => 'Changing your password revokes your MCP tokens and your MCP access. Claude will stop connecting until MCP access is granted to you again and a new token is generated.',
        'current_password' => 'Current password',
        'new_password' => 'New password',
        'new_password_hint' => 'At least :min characters. A few unrelated words work well.',
        'confirm_new_password' => 'Repeat the new password',
        'submit' => 'Change password',
        'cancel' => 'Cancel',
        'changed_title' => 'Password changed',
        'changed_body' => 'Other devices have been signed out.',
    ],

    'password_rule' => [
        'control_character' => 'The new password must not contain control characters, such as line breaks.',
        'too_short' => 'The new password must have at least :min characters.',
        'too_long' => 'The new password must fit in :max bytes. Use a shorter one.',
        'personal' => 'The new password must not contain your name, your email or the company name.',
        'run' => 'The new password must not repeat one character several times in a row.',
        'common' => 'This password is too common. Choose another one.',
        'same_as_current' => 'The new password must differ from the current one.',
    ],

    'reset_command' => [
        'confirm' => 'This replaces the account’s password and ends all its sessions. Continue?',
        'cancelled' => 'Cancelled. Nothing was changed.',
        'refused' => 'Refused. Nothing was changed.',
        'done' => 'Temporary password, shown only once. It works for :hours hours and must be changed at first use. Pass it in person or by phone:',
        'alert_undeliverable' => 'The management chat could not be alerted. Tell the team about this reset yourself.',
    ],

    'alerts' => [
        'password_reset_cli' => 'A staff password was reset from the server console',
    ],
];
