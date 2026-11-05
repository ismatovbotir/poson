<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one credential write path (P24-5): lock the row, increment the session
 * epoch, rotate the remember token, save() and audit, in one transaction.
 * That ends every other session and remember cookie. save() fires
 * UserObserver, which revokes the user's MCP tokens. A non-null
 * $tempPasswordExpiresAt means the user must change the password.
 *
 * $allow runs under the lock; false writes nothing and returns null. Three
 * attempts: RevokeMcpAccess locks in the opposite order when called alone.
 */
class WriteStaffCredentials
{
    /**
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
        return DB::transaction(function () use ($user, $password, $tempPasswordExpiresAt, $auditKey, $auditAction, $by, $ip, $allow): ?User {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($allow !== null && ! $allow($locked)) {
                return null;
            }

            $locked->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'session_epoch' => (int) $locked->session_epoch + 1,
                'temp_password_expires_at' => $tempPasswordExpiresAt,
                'password_changed_at' => now(),
            ])->save();

            SettingsAudit::record(SettingsArea::Users, $auditKey, $auditAction, $by, $ip);

            return $locked;
        }, 3);
    }
}
