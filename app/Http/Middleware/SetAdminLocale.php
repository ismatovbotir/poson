<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin UI language (alias "admin.locale"): the signed-in user's
 * users.locale; for guests (login page) the language picked there, kept in
 * the session; else the default locale.
 *
 * Also registered as Livewire persistent middleware, so it runs again on
 * Livewire update requests from admin pages.
 */
class SetAdminLocale
{
    /**
     * Session key for the language a guest picked on the login page.
     */
    public const SESSION_KEY = 'admin_locale';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        app()->setLocale(static::localeFor(
            $user instanceof User ? $user : ($request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null),
        ));

        return $next($request);
    }

    /**
     * The admin UI locale for a user or a locale code (falls back to the
     * default locale).
     */
    public static function localeFor(User|string|null $userOrLocale): string
    {
        $supported = config('app.supported_locales', []);
        $locale = $userOrLocale instanceof User ? $userOrLocale->locale : $userOrLocale;

        if (is_string($locale) && in_array($locale, $supported, true)) {
            return $locale;
        }

        return (string) config('app.default_locale', 'uz');
    }
}
