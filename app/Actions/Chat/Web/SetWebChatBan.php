<?php

namespace App\Actions\Chat\Web;

use App\Actions\Telegram\InboxRefused;
use App\Enums\AuditAction;
use App\Enums\ChatMode;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Models\WebChat;
use App\Support\Chat\WebChatVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Ban or unban a website chat (plan 28 C5c, WC-14). Policy "ban". The row is
 * KEPT: the visitor's cookie resolves to "unavailable" and no new chat is
 * started while it is present; the ip_hash cooldown is in StartWebChat.
 * Banning also ends the AI context and any open form. Unbanning a purged
 * row (text already deleted by chat:prune) deletes it, so the visitor
 * returns as new. Counts against a per-user hourly limit (Admin exempt),
 * hit before the transaction (WC-3: nothing is refunded by a rollback, no
 * counter row is held under the lock), after a first policy check. Audit row has no
 * text. The version is bumped after commit.
 */
class SetWebChatBan
{
    public const PER_HOUR = 10;

    public const LIMIT_KEY = 'web-chat-ban:';

    /**
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function ban(WebChat $chat, User $actor, ?string $ip): bool
    {
        return $this->write($chat, $actor, $ip, true);
    }

    /**
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function unban(WebChat $chat, User $actor, ?string $ip): bool
    {
        return $this->write($chat, $actor, $ip, false);
    }

    private function write(WebChat $chat, User $actor, ?string $ip, bool $banned): bool
    {
        Gate::forUser($actor)->authorize('ban', $chat);

        if (! $actor->hasRole(Role::Admin) && RateLimiter::hit(self::LIMIT_KEY.$actor->id, 3600) > self::PER_HOUR) {
            throw new InboxRefused('too_many_actions');
        }

        return DB::transaction(function () use ($chat, $actor, $ip, $banned): bool {
            $locked = WebChat::query()->whereKey($chat->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('ban', $locked);

            if ($locked->isBanned() === $banned) {
                return false;
            }

            if ($banned) {
                $locked->forceFill(['banned_at' => now(), 'banned_by_id' => $actor->id, 'mode' => ChatMode::Bot, 'dialog' => null]);
                $locked->resetAiContext();
            } else {
                $locked->forceFill(['banned_at' => null, 'banned_by_id' => null]);
            }

            $locked->save();

            SettingsAudit::record(SettingsArea::TelegramInbox, 'web-chat.'.$locked->id, $banned ? AuditAction::Banned : AuditAction::Unbanned, $actor, $ip);

            if (! $banned && $locked->purged_at !== null && $locked->lead_id === null) {
                $locked->delete();

                return true;
            }

            WebChatVersion::bump($locked->id);

            return true;
        });
    }
}
