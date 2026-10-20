<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel gate (alias "admin"): the signed-in user must be active and
 * have one of the four staff roles. Runs after "auth".
 *
 * A signed-in user who is not (or no longer) staff, e.g. deactivated
 * mid-session, is signed out and sent to the login page with a generic
 * notice. Livewire and JSON requests get a 403 instead of a redirect.
 */
class EnsureStaff
{
    /**
     * Session flash key for the notice shown on the login page.
     */
    public const NOTICE_KEY = 'admin_login_notice';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isStaff()) {
            return $next($request);
        }

        if ($user !== null) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        if (Livewire::isLivewireRequest() || $request->expectsJson()) {
            abort(403);
        }

        return redirect()->route('admin.login')->with(self::NOTICE_KEY, __('admin.auth.session_ended'));
    }
}
