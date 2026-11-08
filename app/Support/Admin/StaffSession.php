<?php

namespace App\Support\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The session's copy of users.session_epoch (plan 24 A1). Every credential
 * write increments the column, which ends every session stamped with an
 * older value. A session with no stamp reads as 0, so sessions that were
 * open before the column existed stay valid until the first bump.
 */
final class StaffSession
{
    public const EPOCH_KEY = 'staff_session_epoch';

    public static function stamp(int $epoch): void
    {
        session()->put(self::EPOCH_KEY, $epoch);
    }

    public static function epoch(): int
    {
        return (int) session(self::EPOCH_KEY, 0);
    }

    public static function isCurrent(User $user): bool
    {
        return self::epoch() === (int) $user->session_epoch;
    }

    /**
     * Sign out this browser only (P24-3). Auth::logout() would also rotate
     * remember_token, so a stale tab would kill a valid remember cookie on
     * another device.
     */
    public static function endCurrentDevice(): void
    {
        Auth::guard('web')->logoutCurrentDevice();

        session()->invalidate();
        session()->regenerateToken();
    }
}
