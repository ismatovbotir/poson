<?php

namespace App\Livewire\Admin\Auth;

use App\Actions\Auth\AuthenticateStaff;
use App\Http\Middleware\EnsureStaff;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Re-enter the password before sensitive changes (/admin/confirm-password,
 * plan 04 S7). On success the confirmation is valid for 15 minutes, the
 * session ID is rotated and the user goes back to the page that sent them
 * here (url.intended, always an admin URL), else to the dashboard.
 *
 * Wrong passwords: limited per user to MAX_ATTEMPTS per minute and
 * MAX_ATTEMPTS_PER_HOUR per hour; each failure is logged (user id + IP);
 * after MAX_CONSECUTIVE_FAILURES in a row (no success in between) the user
 * is signed out and the session invalidated.
 *
 * Dev login: only where AuthenticateStaff::devLoginEnabled() (APP_ENV=local
 * + ADMIN_DEV_LOGIN + APP_DEBUG + private IP) any password is accepted,
 * like the login itself. In every other environment the real password is
 * checked, also for accounts that signed in through the dev login.
 */
#[Layout('layouts::admin-guest')]
class ConfirmPassword extends Component
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public const MAX_ATTEMPTS_PER_HOUR = 20;

    public const MAX_CONSECUTIVE_FAILURES = 10;

    public string $password = '';

    public function confirm(): void
    {
        try {
            $this->validate(['password' => ['required', 'string', 'max:255']], [], ['password' => __('admin.auth.password')]);

            if (! $this->check()) {
                return;
            }
        } finally {
            $this->reset('password');
        }

        session()->migrate(true);
        PasswordConfirmation::confirm();

        $intended = (string) session()->pull('url.intended', '');
        $target = $this->isAdminUrl($intended) ? $intended : route('admin.dashboard');

        $this->redirect($target, navigate: false);
    }

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
     * True when confirmed; false when the user was signed out.
     *
     * @throws ValidationException
     */
    protected function check(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        foreach ([static::minuteKey($user) => self::MAX_ATTEMPTS, static::hourKey($user) => self::MAX_ATTEMPTS_PER_HOUR] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages([
                    'password' => __('admin.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }
        }

        if (AuthenticateStaff::devLoginEnabled()) {
            Log::warning('Admin dev login: password confirmation skipped', ['user_id' => $user->id]);

            return true;
        }

        if (! Hash::check($this->password, (string) $user->getAuthPassword())) {
            return $this->failed($user);
        }

        RateLimiter::clear(static::minuteKey($user));
        Cache::forget(static::failuresKey($user));

        return true;
    }

    /**
     * @throws ValidationException
     */
    protected function failed(User $user): bool
    {
        RateLimiter::hit(static::minuteKey($user), self::DECAY_SECONDS);
        RateLimiter::hit(static::hourKey($user), 3600);

        Cache::add(static::failuresKey($user), 0, now()->addDay());
        $failures = (int) Cache::increment(static::failuresKey($user));

        Log::warning('Admin password confirmation failed', ['user_id' => $user->id, 'ip' => request()->ip(), 'consecutive' => $failures]);

        if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
            Cache::forget(static::failuresKey($user));
            Log::warning('Admin signed out after repeated password confirmation failures', ['user_id' => $user->id, 'ip' => request()->ip()]);

            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();
            session()->flash(EnsureStaff::NOTICE_KEY, __('admin.confirm_password.logged_out'));

            $this->redirect(route('admin.login'), navigate: false);

            return false;
        }

        throw ValidationException::withMessages(['password' => __('admin.confirm_password.failed')]);
    }

    /**
     * Only same-host /admin URLs are followed (no open redirect).
     */
    protected function isAdminUrl(string $url): bool
    {
        $admin = route('admin.dashboard');

        return $url !== '' && ($url === $admin || Str::startsWith($url, rtrim($admin, '/').'/'));
    }

    public function render(): View
    {
        return view('livewire.admin.auth.confirm-password')
            ->title(__('admin.confirm_password.title'));
    }
}
