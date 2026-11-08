<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Admin\StaffSession;
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
 *
 * Plan 24 A1: the same refusal for a session whose epoch no longer matches
 * users.session_epoch (missing = 0) and for an expired temporary password,
 * however the session started, remember cookie included. Those sign out this
 * browser only, so a stale tab can't rotate remember_token for other devices.
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
            if (! $user->hasExpiredTempPassword() && StaffSession::isCurrent($user)) {
                return $next($request);
            }

            StaffSession::endCurrentDevice();
        } elseif ($user !== null) {
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
