<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Enums\TelegramParty;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Mark a bot conversation's person as a client or an employee (plan 41 §4,
 * Telegram → Chats, office.mark = Admin only), or clear it (null). An
 * employee may be linked to an active staff account. Informational only:
 * it never exempts anyone from the AI caps (VO-11). Audited without names.
 */
class SetTelegramParty
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, TelegramUser $telegramUser, ?TelegramParty $party, ?int $staffUserId = null, ?string $ip = null): void
    {
        if (! $actor->can('office.mark') || ! $actor->can('view', $telegramUser)) {
            throw new AuthorizationException;
        }

        $staff = $party === TelegramParty::Employee && $staffUserId !== null ? OfficeStaff::requireActive($staffUserId) : null;

        $telegramUser->forceFill(['party' => $party, 'staff_user_id' => $staff?->id])->save();

        SettingsAudit::record(SettingsArea::Office, 'office.telegram_user.'.$telegramUser->id.'.party', AuditAction::Marked, $actor, $ip);
    }
}
