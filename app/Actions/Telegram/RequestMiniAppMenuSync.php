<?php

namespace App\Actions\Telegram;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Telegram\MiniApp\MiniAppMenuSync;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * "Sync menu buttons" on the admin Mini App page (plan 20 M6, MA-36/37):
 * telegram.bot permission (Admin) and a recent password confirmation,
 * both checked here; then, in one transaction, a SettingsAudit row
 * (sync_requested, actor, IP; never a value) and a new fan-out generation
 * (MiniAppMenuSync::start(), queued after commit).
 */
class RequestMiniAppMenuSync
{
    /**
     * @return int the new generation
     *
     * @throws AuthorizationException
     * @throws TelegramBotException password_required
     */
    public function handle(User $user, ?string $ip): int
    {
        if (! $user->can('telegram.bot')) {
            throw new AuthorizationException;
        }

        if (! PasswordConfirmation::isRecent()) {
            throw new TelegramBotException('password_required');
        }

        return DB::transaction(function () use ($user, $ip): int {
            SettingsAudit::record(SettingsArea::TelegramBot, MiniAppMenuSync::STATE_KEY, AuditAction::SyncRequested, $user, $ip);

            return MiniAppMenuSync::start();
        });
    }
}
