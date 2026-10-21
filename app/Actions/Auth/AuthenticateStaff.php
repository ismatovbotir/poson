<?php

namespace App\Actions\Auth;

use App\Actions\Users\CreateStaffUser;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Admin panel sign-in. Only active staff (one of the four roles) can sign in.
 *
 * Failures always return the same generic message, so it doesn't reveal
 * whether the email exists, the account is inactive or has no staff role.
 * Rate limited per DECAY_SECONDS: MAX_ATTEMPTS failed attempts per
 * email + IP, and MAX_ATTEMPTS_PER_IP failed attempts per IP (any email).
 *
 * Dev login (local only, see devLoginEnabled()): the password is not checked.
 * An existing active staff email signs in as that user; any other email
 * signs in as DEV_EMAIL (an admin, created when missing).
 */
class AuthenticateStaff
{
    public const MAX_ATTEMPTS = 5;

    public const MAX_ATTEMPTS_PER_IP = 20;

    public const DECAY_SECONDS = 60;

    /**
     * Account used by the dev login for emails that aren't active staff.
     */
    public const DEV_EMAIL = 'admin@poson.test';

    public function __construct(protected CreateStaffUser $createStaffUser) {}

    /**
     * Networks the dev login accepts requests from: loopback and private ranges.
     *
     * @var list<string>
     */
    public const DEV_LOGIN_NETWORKS = ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /**
     * The switch for the dev login, defense in depth: APP_ENV=local AND
     * admin.dev_login AND app.debug AND a loopback / private client IP.
     * Impossible in production, staging or testing, even with the flag set.
     */
    public static function devLoginEnabled(): bool
    {
        return app()->environment('local')
            && config('admin.dev_login') === true
            && config('app.debug') === true
            && IpUtils::checkIp((string) request()->ip(), self::DEV_LOGIN_NETWORKS);
    }

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

        if (static::devLoginEnabled()) {
            return $this->devLogin($email, $remember, $field);
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

    /**
     * Sign in without a password check (local dev only).
     *
     * @throws ValidationException
     */
    protected function devLogin(string $email, bool $remember, string $field): User
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if (! $user?->isStaff()) {
            $user = User::query()->where('email', self::DEV_EMAIL)->first() ?? $this->createDevAdmin();
        }

        if (! $user->isStaff()) {
            throw ValidationException::withMessages([$field => __('admin.auth.failed')]);
        }

        Auth::guard('web')->login($user, $remember);

        Log::warning('Admin dev login used', ['user_id' => $user->id]);

        return $user;
    }

    /**
     * Never seeds from a web request: the roles must exist already.
     *
     * @throws RuntimeException
     */
    protected function createDevAdmin(): User
    {
        if (! SpatieRole::query()->where('name', Role::Admin->value)->exists()) {
            throw new RuntimeException('Dev login: the admin role is missing. Run `php artisan db:seed` first.');
        }

        return $this->createStaffUser->handle('Admin', self::DEV_EMAIL, Str::password(32), Role::Admin);
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
