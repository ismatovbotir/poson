<?php

namespace App\Livewire\Admin\Concerns;

use App\Actions\Auth\AuthenticateStaff;
use App\Actions\Users\WriteStaffCredentials;
use App\Enums\AuditAction;
use App\Http\Middleware\EnsureStaff;
use App\Models\User;
use App\Support\Admin\StaffSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Check a signed-in staff member's password, for ConfirmPassword and the
 * change-password screen (P24-6). Both count on the same keys.
 *
 * Wrong passwords: limited per user to MAX_ATTEMPTS per minute and
 * MAX_ATTEMPTS_PER_HOUR per hour; each failure is logged (user id + IP);
 * after MAX_CONSECUTIVE_FAILURES in a row (no success in between) the user
 * is signed out and the session invalidated.
 */
trait ChecksStaffPassword
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public const MAX_ATTEMPTS_PER_HOUR = 20;

    public const MAX_CONSECUTIVE_FAILURES = 10;

    public static function minuteKey(User $user): string
    {
        return 'admin-confirm-password:'.$user->id;
    }

    public static function hourKey(User $user): string
    {
        return 'admin-confirm-password-hour:'.$user->id;
    }

    public static function failuresKey(User $user): string
    {
        return 'admin-confirm-password-failures:'.$user->id;
    }

    /**
     * True when the password is right; false when the user was signed out.
     *
     * @param  string  $field  Form field the error is attached to
     * @param  bool  $allowDevLogin  Accept any password where the local dev login is on
     *
     * @throws ValidationException
     */
    protected function checkStaffPassword(User $user, #[\SensitiveParameter] string $password, string $field, bool $allowDevLogin): bool
    {
        foreach ([static::minuteKey($user) => self::MAX_ATTEMPTS, static::hourKey($user) => self::MAX_ATTEMPTS_PER_HOUR] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    $field => __('admin.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }
        }

        if ($allowDevLogin && AuthenticateStaff::devLoginEnabled()) {
            Log::warning('Admin dev login: password confirmation skipped', ['user_id' => $user->id]);

            return true;
        }

        if (! Hash::check($password, (string) $user->getAuthPassword())) {
            return $this->staffPasswordFailed($user, $field);
        }

        RateLimiter::clear(static::minuteKey($user));
        Cache::forget(static::failuresKey($user));

        return true;
    }

    /**
     * @throws ValidationException
     */
    protected function staffPasswordFailed(User $user, string $field): bool
    {
        RateLimiter::hit(static::minuteKey($user), self::DECAY_SECONDS);
        RateLimiter::hit(static::hourKey($user), 3600);

        Cache::add(static::failuresKey($user), 0, now()->addDay());
        $failures = (int) Cache::increment(static::failuresKey($user));

        Log::warning('Admin password confirmation failed', ['user_id' => $user->id, 'ip' => request()->ip(), 'consecutive' => $failures]);

        if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
            Cache::forget(static::failuresKey($user));
            Log::warning('Admin signed out after repeated password confirmation failures', ['user_id' => $user->id, 'ip' => request()->ip()]);

            // Every device: epoch bump + remember rotation + audit row, then this browser.
            app(WriteStaffCredentials::class)->endSessions(
                $user,
                'user.'.$user->id.'.sessions',
                AuditAction::Updated,
                $user,
                request()->ip(),
            );
            StaffSession::endCurrentDevice();
            session()->flash(EnsureStaff::NOTICE_KEY, __('admin.confirm_password.logged_out'));

            $this->redirect(route('admin.login'), navigate: false);

            return false;
        }

        throw ValidationException::withMessages([$field => __('admin.confirm_password.failed')]);
    }
}
