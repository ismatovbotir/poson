<?php

namespace App\Support\Admin;

use App\Jobs\SendTelegramMessage;
use App\Models\User;
use App\Support\Mcp\McpAlerts;
use App\Support\Telegram\TelegramLocale;
use Throwable;

/**
 * Staff account alerts for the Telegram management chat (P24-9), queued in
 * the Telegram language. The name is escaped; never a password or an email.
 * A failed alert never breaks the action it reports.
 */
final class StaffAlerts
{
    /**
     * True when the alert was queued for a configured chat; the caller warns
     * the operator otherwise.
     */
    public static function passwordResetFromConsole(User $user): bool
    {
        if (! McpAlerts::isConfigured()) {
            return false;
        }

        try {
            $locale = TelegramLocale::current();

            SendTelegramMessage::dispatch(
                McpAlerts::CHAT,
                '<b>'.e(__('staff.alerts.password_reset_cli', [], $locale)).'</b>'."\n".e($user->name),
                [],
                'staff.password_cli',
            )->afterCommit();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
