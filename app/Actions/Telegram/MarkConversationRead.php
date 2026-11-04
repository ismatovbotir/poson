<?php

namespace App\Actions\Telegram;

use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Staff opened a bot conversation: clear its unread counter (plan 21 A1).
 *
 * Authorizes "view" for the actor and updates exactly the given row, so it can
 * never touch another thread. Returns whether anything changed.
 */
class MarkConversationRead
{
    public function handle(TelegramUser $conversation, User $actor): bool
    {
        Gate::forUser($actor)->authorize('view', $conversation);

        return TelegramUser::query()
            ->whereKey($conversation->getKey())
            ->where('unread_count', '>', 0)
            ->update(['unread_count' => 0]) > 0;
    }
}
