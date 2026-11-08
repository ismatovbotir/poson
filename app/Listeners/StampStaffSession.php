<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Admin\StaffSession;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * Runs on every web sign-in, including remember-cookie sign-ins. Stamps the
 * epoch that was loaded with the credential check, never a fresh value
 * (P24-1): a bump that lands between the check and this stamp leaves the
 * session stale, so the next request is refused. Synchronous on purpose.
 */
class StampStaffSession
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        StaffSession::stamp((int) $event->user->session_epoch);

        // A remember cookie of a refused account also fires Login: EnsureStaff ends that session.
        if ($event->user->canSignIn()) {
            DB::table('users')->where('id', $event->user->id)->update(['last_login_at' => now()]);
        }
    }
}
