<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Admin\StaffSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POST /admin/logout: sign out, invalidate the session, new CSRF token. A stale
 * session signs out this browser only, so an old tab can't rotate remember_token
 * for the user's current devices (P24-3).
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && ! StaffSession::isCurrent($user)) {
            StaffSession::endCurrentDevice();

            return redirect()->route('admin.login');
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
