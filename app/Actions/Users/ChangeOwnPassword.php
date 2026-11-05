<?php

namespace App\Actions\Users;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * A staff member changes their own password (P24-5). Under the row lock the
 * session must still match the row's epoch and the current password must
 * match the locked hash, so a reset or a second change that landed after the
 * screen's own check is not overwritten. Returns the saved user, or null when
 * refused (the caller signs this browser out).
 */
class ChangeOwnPassword
{
    public function __construct(protected WriteStaffCredentials $writeStaffCredentials) {}

    public function handle(
        User $user,
        int $sessionEpoch,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
        ?string $ip,
    ): ?User {
        return $this->writeStaffCredentials->handle(
            $user,
            $newPassword,
            null,
            'user.'.$user->id.'.password',
            AuditAction::PasswordChanged,
            $user,
            $ip,
            fn (User $locked): bool => $locked->canSignIn()
                && (int) $locked->session_epoch === $sessionEpoch
                && Hash::check($currentPassword, (string) $locked->getAuthPassword()),
        );
    }
}
