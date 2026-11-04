<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ban or unban a bot user (plan 21 A4, TG-35). Policy "ban" (view + telegram.ban)
 * is checked against the row as it is now; the change is a conditional update,
 * so only a real change writes the Activity and SettingsAudit rows (no name or
 * text). A banned user's updates are ignored by HandleTelegramUpdate, and
 * nothing is delivered to them.
 */
class SetConversationBan
{
    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     */
    public function ban(TelegramUser $conversation, User $actor, ?string $ip): bool
    {
        return $this->write($conversation, $actor, $ip, true);
    }

    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     */
    public function unban(TelegramUser $conversation, User $actor, ?string $ip): bool
    {
        return $this->write($conversation, $actor, $ip, false);
    }

    /**
     * @throws AuthorizationException
     */
    private function write(TelegramUser $conversation, User $actor, ?string $ip, bool $banned): bool
    {
        return DB::transaction(function () use ($conversation, $actor, $ip, $banned): bool {
            $fresh = TelegramUser::query()->findOrFail($conversation->getKey());

            Gate::forUser($actor)->authorize('ban', $fresh);

            $query = TelegramUser::query()->whereKey($fresh->id);
            $banned ? $query->whereNull('banned_at') : $query->whereNotNull('banned_at');

            if ($query->update(['banned_at' => $banned ? now() : null, 'banned_by_id' => $banned ? $actor->id : null]) !== 1) {
                return false;
            }

            $fresh->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::StatusChange,
                'meta' => ['action' => $banned ? 'ban' : 'unban'],
                'created_at' => now(),
            ]);

            SettingsAudit::record(SettingsArea::TelegramBot, 'telegram.conversation.'.$fresh->id, $banned ? AuditAction::Banned : AuditAction::Unbanned, $actor, $ip);

            return true;
        });
    }
}
