<?php

namespace App\Support\Quotes;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Monday–Friday arithmetic for quote validity (plan 06: 5 working days).
 * Public holidays are not taken into account.
 */
final class WorkingDays
{
    /**
     * The date $days working days after $from (weekend days are skipped).
     */
    public static function add(CarbonInterface $from, int $days): CarbonImmutable
    {
        $date = CarbonImmutable::instance($from)->startOfDay();

        while ($days > 0) {
            $date = $date->addDay();

            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date;
    }
}
