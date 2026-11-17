<?php

namespace App\Actions\Chat\Web;

use App\Actions\Telegram\InboxRefused;
use App\Models\User;
use App\Models\WebChat;
use App\Support\Chat\WebChatVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Give a website chat to a manager or take an unassigned one (plan 28 C5c;
 * mirrors AssignConversation). Policy "assign" is checked against the row
 * as it is now, the target is an active user with telegram.inbox, and the
 * write is conditional on the owner just read (a lost race refuses).
 */
class AssignWebChat
{
    /**
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function assign(WebChat $chat, User $actor, int $targetId): bool
    {
        return $this->write($chat, $actor, $targetId, false);
    }

    /**
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function take(WebChat $chat, User $actor): bool
    {
        return $this->write($chat, $actor, $actor->id, true);
    }

    /**
     * @return Collection<int, User>
     */
    public function candidates(): Collection
    {
        return User::query()->permission('telegram.inbox')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function write(WebChat $chat, User $actor, int $targetId, bool $take): bool
    {
        return DB::transaction(function () use ($chat, $actor, $targetId, $take): bool {
            $fresh = WebChat::query()->whereKey($chat->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('assign', $fresh);

            $target = User::query()->whereKey($targetId)->where('is_active', true)->first();

            if (! $target instanceof User || ! $target->can('telegram.inbox')) {
                throw new InboxRefused('invalid_target');
            }

            if ($fresh->manager_id === $target->id) {
                return false;
            }

            if ($take && $fresh->manager_id !== null) {
                throw new InboxRefused('taken');
            }

            $fresh->forceFill(['manager_id' => $target->id])->save();
            WebChatVersion::bump($fresh->id);

            return true;
        });
    }
}
