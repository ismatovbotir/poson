<?php

namespace App\Actions\Chat\Web;

use App\Actions\Telegram\InboxRefused;
use App\Enums\ChatMode;
use App\Enums\ConversationStatus;
use App\Models\User;
use App\Models\WebChat;
use App\Support\Chat\WebChatVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Close or reopen a website chat (plan 28 C5c). Policy "close" (view).
 * Close ends the chat for the visitor (back to the bot, dialog cleared, new
 * AI context; the visitor's next poll finds no chat and the cookie is
 * forgotten). Reopen is only possible for a chat that is neither banned
 * nor expired. The version is bumped after commit.
 */
class ChangeWebChatStatus
{
    /**
     * @return bool whether the status changed
     *
     * @throws AuthorizationException
     */
    public function close(WebChat $chat, User $actor): bool
    {
        return DB::transaction(function () use ($chat, $actor): bool {
            $locked = WebChat::query()->whereKey($chat->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('close', $locked);

            if ($locked->isClosed()) {
                return false;
            }

            $locked->forceFill([
                'conversation_status' => ConversationStatus::Closed,
                'closed_at' => now(),
                'mode' => ChatMode::Bot,
                'dialog' => null,
            ]);
            $locked->resetAiContext();
            $locked->save();
            WebChatVersion::bump($locked->id);

            return true;
        });
    }

    /**
     * @return bool whether the status changed
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function reopen(WebChat $chat, User $actor): bool
    {
        return DB::transaction(function () use ($chat, $actor): bool {
            $locked = WebChat::query()->whereKey($chat->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('close', $locked);

            if (! $locked->isClosed()) {
                return false;
            }

            if ($locked->isBanned()) {
                throw new InboxRefused('banned');
            }

            if ($locked->isExpired()) {
                throw new InboxRefused('expired');
            }

            $locked->forceFill(['conversation_status' => ConversationStatus::Open, 'closed_at' => null])->save();
            WebChatVersion::bump($locked->id);

            return true;
        });
    }
}
