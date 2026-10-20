<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin panel sign-in. Only active staff (one of the four roles) can sign in.
 *
 * Failures always return the same generic message, so it doesn't reveal
 * whether the email exists, the account is inactive or has no staff role.
 * Rate limited per DECAY_SECONDS: MAX_ATTEMPTS failed attempts per
 * email + IP, and MAX_ATTEMPTS_PER_IP failed attempts per IP (any email).
 */
class AuthenticateStaff
{
    public const MAX_ATTEMPTS = 5;

    public const MAX_ATTEMPTS_PER_IP = 20;

    public const DECAY_SECONDS = 60;

    /**
     * Sign the user in (session regeneration is up to the caller).
     *
     * @param  string  $field  Form field the error is attached to
     *
     * @throws ValidationException
     */
    public function handle(string $email, #[\SensitiveParameter] string $password, bool $remember, string $ip, string $field = 'email'): User
    {
        $key = static::throttleKey($email, $ip);
        $ipKey = static::ipThrottleKey($ip);

        foreach ([$key => self::MAX_ATTEMPTS, $ipKey => self::MAX_ATTEMPTS_PER_IP] as $limiterKey => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($limiterKey, $maxAttempts)) {
                event(new Lockout(request()));

                throw ValidationException::withMessages([
                    $field => __('admin.auth.throttle', ['seconds' => RateLimiter::availableIn($limiterKey)]),
                ]);
            }
        }

        $authenticated = Auth::guard('web')->attemptWhen(
            ['email' => $email, 'password' => $password],
            fn (User $user): bool => $user->isStaff(),
            $remember,
        );

        if (! $authenticated) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                $field => __('admin.auth.failed'),
            ]);
        }

        RateLimiter::clear($key);

        /** @var User */
        return Auth::guard('web')->user();
    }

    public static function throttleKey(string $email, string $ip): string
    {
        return 'admin-login:'.Str::transliterate(Str::lower(trim($email))).'|'.$ip;
    }

    public static function ipThrottleKey(string $ip): string
    {
        return 'admin-login-ip:'.$ip;
    }
}
