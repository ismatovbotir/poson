<?php

namespace App\Actions\Chat\Web;

use App\Models\WebChat;
use App\Support\Chat\WebChatToken;

/**
 * The visitor's website chat from the cookie, on every call (plan 28 C5,
 * CB-18, WC-5, WC-6, WC-14). There is no chat id or token in any Livewire
 * property or method argument: only the cookie identifies the chat.
 *
 * A malformed token is no chat (shape-checked before hashing). Otherwise one
 * query through the unique token_hash index, then:
 *  - banned → banned (refused), the cookie is KEPT, so StartWebChat refuses
 *    while it is present;
 *  - closed or expired (no message for chatbot.web.expire_hours, even before
 *    any prune ran) → no chat, and the stale cookie is forgotten;
 *  - otherwise the chat.
 * The visitor is never told which check failed.
 */
class ResolveWebChat
{
    public function __construct(private readonly WebChatToken $token) {}

    public function handle(): ResolvedWebChat
    {
        $token = $this->token->current();

        if ($token === null) {
            return new ResolvedWebChat;
        }

        $chat = WebChat::query()->where('token_hash', WebChatToken::hash($token))->first();

        if ($chat === null) {
            return new ResolvedWebChat;
        }

        if ($chat->isBanned()) {
            return new ResolvedWebChat(banned: true);
        }

        if ($chat->isClosed() || $chat->isExpired()) {
            $this->token->forget();

            return new ResolvedWebChat;
        }

        return new ResolvedWebChat($chat);
    }
}
