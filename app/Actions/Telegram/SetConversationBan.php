<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Enums\TelegramUserState;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Ban or unban a bot user (plan 21 A4, TG-35, T21-34, T21-35). Policy "ban"
 * (view + telegram.ban) is checked against the row as it is now. A real change
 * counts against the per-user atomic limit, is a conditional update, and writes
 * the Activity and SettingsAudit rows (no name or text). A repeat that changes
 * nothing uses up no limit.
 *
 * Unbanning a scrubbed row (state Language, not registered: the retention scrub
 * kept it for the ban only) deletes it, so the user returns as a new visitor and
 * is not caught by prune. Unbanning any other row keeps its registration. A
 * banned user's updates are ignored by HandleTelegramUpdate, and nothing is
 * delivered to them.
 */
class SetConversationBan
{
    public const PER_HOUR = 10;

    public const LIMIT_KEY = 'telegram-inbox-ban:';

    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function ban(TelegramUser $conversation, User $actor, ?string $ip): bool
    {
        return $this->write($conversation, $actor, $ip, true);
    }

    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function unban(TelegramUser $conversation, User $actor, ?string $ip): bool
    {
        return $this->write($conversation, $actor, $ip, false);
    }

    /**
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    private function write(TelegramUser $conversation, User $actor, ?string $ip, bool $banned): bool
    {
        return DB::transaction(function () use ($conversation, $actor, $ip, $banned): bool {
            $fresh = TelegramUser::query()->findOrFail($conversation->getKey());

            Gate::forUser($actor)->authorize('ban', $fresh);

            if ($fresh->isBanned() === $banned) {
                return false;
            }

            // Atomic: the hit itself decides.
            if (RateLimiter::hit(self::LIMIT_KEY.$actor->id, 3600) > self::PER_HOUR) {
                throw new InboxRefused('too_many_actions');
            }

            $query = TelegramUser::query()->whereKey($fresh->id);
            $banned ? $query->whereNull('banned_at') : $query->whereNotNull('banned_at');

            if ($query->update(['banned_at' => $banned ? now() : null, 'banned_by_id' => $banned ? $actor->id : null]) !== 1) {
                RateLimiter::decrement(self::LIMIT_KEY.$actor->id);

                return false;
            }

            SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.'.$fresh->id, $banned ? AuditAction::Banned : AuditAction::Unbanned, $actor, $ip);

            if (! $banned && $this->isScrubbed($fresh)) {
                $fresh->activities()->delete();
                $fresh->delete();

                return true;
            }

            $fresh->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::StatusChange,
                'meta' => ['action' => $banned ? 'ban' : 'unban'],
                'created_at' => now(),
            ]);

            return true;
        });
    }

    private function isScrubbed(TelegramUser $row): bool
    {
        return $row->state === TelegramUserState::Language && ! $row->isRegistered();
    }
}
