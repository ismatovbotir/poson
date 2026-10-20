<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\UpdateUserLocale;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetAdminLocale;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /admin/locale: switch the admin UI language. Saved to users.locale
 * for staff; kept in the session otherwise (login page guests).
 */
class UpdateLocaleController extends Controller
{
    public function __invoke(Request $request, UpdateUserLocale $updateUserLocale): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('app.supported_locales'))],
        ]);

        $user = $request->user();
        $isStaff = $user instanceof User && $user->isStaff();

        if ($isStaff) {
            $updateUserLocale->handle($user, $validated['locale']);
        } else {
            $request->session()->put(SetAdminLocale::SESSION_KEY, $validated['locale']);
        }

        $fallback = route($isStaff ? 'admin.dashboard' : 'admin.login');

        return redirect()->to($this->isAdminPage(url()->previous($fallback)) ? url()->previous($fallback) : $fallback);
    }

    /**
     * Only redirect back to an admin page of this app (the Referer is client-controlled).
     */
    protected function isAdminPage(string $url): bool
    {
        $parts = parse_url($url);
        $app = parse_url(url('/'));

        if (! is_array($parts) || ! is_array($app)) {
            return false;
        }

        foreach (['scheme', 'host', 'port'] as $component) {
            if (($parts[$component] ?? null) !== ($app[$component] ?? null)) {
                return false;
            }
        }

        $path = $parts['path'] ?? '/';

        return $path === '/admin' || str_starts_with($path, '/admin/');
    }
}
