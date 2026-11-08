<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The one credential write path (P24-5): lock the row, increment the session
 * epoch, rotate the remember token, save() and audit, in one transaction.
 * That ends every other session and remember cookie. save() fires
 * UserObserver, which revokes the user's MCP access on a password change or a
 * deactivation. handle() replaces the password; endSessions() is the same
 * write without one, for role changes and deactivation.
 *
 * The password is always hashed here: the model's "hashed" cast stores a value
 * that already looks like a hash as it is, which would let a submitted hash
 * replace the password and skip the password rule.
 *
 * $allow runs under the lock; false writes nothing and returns null. Three
 * attempts: RevokeMcpAccess locks in the opposite order when called alone.
 */
class WriteStaffCredentials
{
    /**
     * Replace the password. A non-null $tempPasswordExpiresAt means the user
     * must change it.
     *
     * @param  (Closure(User): bool)|null  $allow
     */
    public function handle(
        User $user,
        #[\SensitiveParameter] string $password,
        ?CarbonInterface $tempPasswordExpiresAt,
        string $auditKey,
        AuditAction $auditAction,
        ?User $by,
        ?string $ip,
        ?Closure $allow = null,
    ): ?User {
        return $this->write($user, [
            'password' => Hash::make($password),
            'temp_password_expires_at' => $tempPasswordExpiresAt,
            'password_changed_at' => now(),
        ], $auditKey, $auditAction, $by, $ip, $allow);
    }

    /**
     * End every session and remember cookie without touching the password.
     * $isActive, when given, is saved in the same write, so a deactivation
     * reaches UserObserver.
     *
     * @param  (Closure(User): bool)|null  $allow
     */
    public function endSessions(
        User $user,
        string $auditKey,
        AuditAction $auditAction,
        ?User $by,
        ?string $ip,
        ?Closure $allow = null,
        ?bool $isActive = null,
    ): ?User {
        return $this->write($user, $isActive === null ? [] : ['is_active' => $isActive], $auditKey, $auditAction, $by, $ip, $allow);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  (Closure(User): bool)|null  $allow
     */
    private function write(
        User $user,
        #[\SensitiveParameter] array $attributes,
        string $auditKey,
        AuditAction $auditAction,
        ?User $by,
        ?string $ip,
        ?Closure $allow,
    ): ?User {
        return DB::transaction(function () use ($user, $attributes, $auditKey, $auditAction, $by, $ip, $allow): ?User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($allow !== null && ! $allow($locked)) {
                return null;
            }

            $locked->forceFill([
                ...$attributes,
                'remember_token' => Str::random(60),
                'session_epoch' => (int) $locked->session_epoch + 1,
            ])->save();

            SettingsAudit::record(SettingsArea::Users, $auditKey, $auditAction, $by, $ip);

            return $locked;
        }, 3);
    }
}
