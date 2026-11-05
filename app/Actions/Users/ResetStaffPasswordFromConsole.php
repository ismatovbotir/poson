<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Models\User;
use App\Support\Admin\StaffAlerts;
use App\Support\Admin\TempPassword;
use Illuminate\Support\Str;

/**
 * Recovery from the server console (P24-9, staff:reset-password): a temporary
 * password for an active staff member, for when no Admin can sign in. Unknown,
 * non-staff and inactive accounts are refused the same way and an account is
 * never reactivated. Audited with a null user.
 */
class ResetStaffPasswordFromConsole
{
    public function __construct(
        protected WriteStaffCredentials $writeStaffCredentials,
        protected TempPassword $tempPassword,
    ) {}

    /**
     * @return array{password: string, alerted: bool}|null null when refused
     */
    public function handle(string $email): ?array
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if (! $user?->isStaff()) {
            return null;
        }

        $password = $this->tempPassword->generate();

        $saved = $this->writeStaffCredentials->handle(
            $user,
            $password,
            $this->tempPassword->expiresAt(),
            'user.'.$user->id.'.password.cli',
            AuditAction::PasswordReset,
            null,
            null,
            fn (User $locked): bool => $locked->isStaff(),
        );

        if ($saved === null) {
            return null;
        }

        return ['password' => $password, 'alerted' => StaffAlerts::passwordResetFromConsole($saved)];
    }
}
