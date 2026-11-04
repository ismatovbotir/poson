<?php

namespace App\Actions\Telegram;

use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Staff opened a bot conversation: clear its unread counter (plan 21 A1).
 *
 * Authorizes "view" for the actor and updates exactly the given row, so it can
 * never touch another thread. Provisional owner rule: only the assigned manager
 * (or anyone, while the thread is unassigned) clears the counter; an Admin or
 * other viewer of someone else's thread leaves it unchanged. No UPDATE when the
 * counter is already 0, since this runs on every poll. Returns whether anything changed.
 */
class MarkConversationRead
{
    public function handle(TelegramUser $conversation, User $actor): bool
    {
        Gate::forUser($actor)->authorize('view', $conversation);

        if ($conversation->unread_count === 0 || ($conversation->manager_id !== null && $conversation->manager_id !== $actor->id)) {
            return false;
        }

        return TelegramUser::query()
            ->whereKey($conversation->getKey())
            ->where('unread_count', '>', 0)
            ->where(fn ($query) => $query->whereNull('manager_id')->orWhere('manager_id', $actor->id))
            ->update(['unread_count' => 0]) > 0;
    }
}
