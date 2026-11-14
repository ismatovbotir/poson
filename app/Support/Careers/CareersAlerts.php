<?php

namespace App\Support\Careers;

use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Support\Facades\Cache;

/**
 * Counts-only management alerts of the careers CV flow (plan 31, CR-3 /
 * CR-5 / CR-9): one per reason and window, never a name, phone or file.
 * Reasons: cap (a site-wide cap reached, CVs saved text-only),
 * refused_target (the management chat is public, a channel or unusable),
 * send_failed (Telegram refused a CV), expired (pending CVs past 24 h),
 * delete_failed (a pending file could not be deleted). A failed send
 * shortens the window to 5 minutes, like ForwardAlerts::once().
 */
final class CareersAlerts
{
    public const MONITORING_EVENT = 'careers_cv';

    public static function once(string $reason, ?int $count = null, int $seconds = 3600): void
    {
        $key = 'careers-alert:'.$reason;

        if (! Cache::add($key, true, $seconds)) {
            return;
        }

        $values = ['reason' => __('telegram.monitoring.careers_reasons.'.$reason, [], TelegramLocale::current())];

        if ($count !== null) {
            $values['count'] = $count;
        }

        if (MonitoringAlerts::problem(self::MONITORING_EVENT, $values) === MonitoringAlerts::FAILED) {
            Cache::put($key, true, min($seconds, 300));
        }
    }
}
