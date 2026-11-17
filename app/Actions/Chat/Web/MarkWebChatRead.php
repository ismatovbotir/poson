<?php

namespace App\Actions\Chat\Web;

use App\Models\User;
use App\Models\WebChat;
use App\Support\Chat\WebChatVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Staff opened a website chat: clear its unread counter (plan 28 C5c). Same
 * rule as MarkConversationRead: only the assigned manager (or anyone while
 * unassigned) clears it. No write when already 0 (this runs on every poll).
 */
class MarkWebChatRead
{
    public function handle(WebChat $chat, User $actor): bool
    {
        Gate::forUser($actor)->authorize('view', $chat);

        if ($chat->unread_count === 0 || ($chat->manager_id !== null && $chat->manager_id !== $actor->id)) {
            return false;
        }

        return DB::transaction(function () use ($chat, $actor): bool {
            $changed = WebChat::query()
                ->whereKey($chat->getKey())
                ->where('unread_count', '>', 0)
                ->where(fn ($query) => $query->whereNull('manager_id')->orWhere('manager_id', $actor->id))
                ->update(['unread_count' => 0]) > 0;

            if ($changed) {
                WebChatVersion::bump($chat->id);
            }

            return $changed;
        });
    }
}
