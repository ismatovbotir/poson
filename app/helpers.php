<?php

use App\Support\LocaleUrl;

if (! function_exists('localized_url')) {
    /**
     * URL of the current page in the given locale (language switcher, hreflang).
     */
    function localized_url(string $locale): string
    {
        return LocaleUrl::switch($locale);
    }
}
