<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Support\LocaleUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedirectToLocale extends Controller
{
    /**
     * Redirect "/" to the visitor's locale: cookie, then Accept-Language, then the default.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $supported = config('app.supported_locales');
        $default = LocaleUrl::defaultLocale();

        $cookie = $request->cookie(SetLocale::COOKIE);

        $locale = match (true) {
            is_string($cookie) && in_array($cookie, $supported, true) => $cookie,
            default => $this->preferredLocale($request, $supported, $default) ?? $default,
        };

        return redirect()
            ->route('site.home', ['locale' => $locale], 302)
            ->setVary('Accept-Language, Cookie');
    }

    /**
     * @param  array<int, string>  $supported
     */
    private function preferredLocale(Request $request, array $supported, string $default): ?string
    {
        if (blank($request->header('Accept-Language'))) {
            return null;
        }

        // Symfony matches "ru-RU" to "ru" and falls back to the first locale of
        // the list, so put the default first.
        $candidates = array_values(array_unique([$default, ...$supported]));
        $preferred = $request->getPreferredLanguage($candidates);

        return in_array($preferred, $supported, true) ? $preferred : null;
    }
}
