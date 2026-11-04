<?php

namespace App\Support\Telegram\Bot;

use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Support\Facades\Cache;

/**
 * Management-chat alerts about the customer inbox (B4-S8), deduped per reason
 * and window. Shared by ForwardTelegramMedia and TelegramInboxMessage.
 */
final class ForwardAlerts
{
    public const MONITORING_EVENT = 'telegram_forward';

    /**
     * One alert per reason and window. When the send fails the dedupe window
     * is shortened to 5 minutes at most, so the alert is neither lost for the
     * whole window nor retried (blocking the worker) on every event.
     */
    public static function once(string $reason, int $seconds): void
    {
        $key = 'telegram-forward-alert:'.$reason;

        if (! Cache::add($key, true, $seconds)) {
            return;
        }

        $result = MonitoringAlerts::problem(self::MONITORING_EVENT, [
            'reason' => __('telegram.monitoring.forward_reasons.'.$reason, [], TelegramLocale::current()),
        ]);

        if ($result === MonitoringAlerts::FAILED) {
            // Send failed: retry sooner than the full window, but never on every event.
            Cache::put($key, true, min($seconds, 300));
        }
    }
}
