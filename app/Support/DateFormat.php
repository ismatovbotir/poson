<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Localized dates for screens. Carbon's plain "uz" locale is Cyrillic
 * ("октябр"); the site and admin write Uzbek in Latin, so uz maps to
 * uz_Latn ("Oktabr") here, in one place.
 */
final class DateFormat
{
    public static function carbonLocale(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'uz' ? 'uz_Latn' : $locale;
    }

    /**
     * isoFormat() in the current (or given) locale; null for a null date.
     */
    public static function iso(?CarbonInterface $date, string $format, ?string $locale = null): ?string
    {
        return $date?->locale(self::carbonLocale($locale))->isoFormat($format);
    }
}
