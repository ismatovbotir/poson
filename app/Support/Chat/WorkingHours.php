<?php

namespace App\Support\Chat;

use App\Support\CompanySettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Whether managers answer now (plan 28 §5, CB-31): the chat bot's weekly
 * hours (Admin → Chat bot → Settings), or the company opening hours when
 * "use the company working hours" is on and the company has any. Always
 * Asia/Tashkent, immutable Carbon. Used only when a chat is handed off, to
 * add the after-hours text; never by the matching.
 */
final class WorkingHours
{
    public const TIMEZONE = 'Asia/Tashkent';

    public static function isOpen(?DateTimeInterface $at = null): bool
    {
        $now = CarbonImmutable::instance($at ?? now())->setTimezone(self::TIMEZONE);
        $day = ChatSettings::DAYS[$now->dayOfWeekIso - 1];
        $time = $now->format('H:i');
        $settings = ChatSettings::all();

        if ($settings['use_company_hours']) {
            $rows = app(CompanySettings::class)->openingHours();

            if ($rows !== []) {
                foreach ($rows as $row) {
                    if (in_array($day, $row['days'], true) && $time >= $row['opens'] && $time < $row['closes']) {
                        return true;
                    }
                }

                return false;
            }
        }

        $hours = $settings['hours'][$day];

        return $hours['open'] && $time >= $hours['from'] && $time < $hours['to'];
    }
}
