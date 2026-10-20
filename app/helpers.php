<?php

use App\Support\LocaleUrl;

if (! function_exists('localized_url')) {
    /**
     * URL of the current page in the given locale, query string kept (language switcher).
     * Use LocaleUrl::clean() for canonical and hreflang URLs.
     */
    function localized_url(string $locale): string
    {
        return LocaleUrl::switch($locale);
    }
}
