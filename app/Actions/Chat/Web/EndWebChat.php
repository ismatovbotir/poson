<?php

namespace App\Actions\Chat\Web;

use App\Enums\ChatAuthor;
use App\Enums\ChatMode;
use App\Enums\ConversationStatus;
use App\Models\WebChat;
use App\Support\Chat\WebChatSwitch;
use App\Support\Chat\WebChatToken;
use App\Support\Chat\WebChatVersion;
use Illuminate\Support\Facades\DB;

/**
 * The visitor's "End chat" (plan 28 C5 §19.3, CB-18, W-9, WC-14): the chat
 * from the cookie is closed (closed_at, status closed, back to the bot, the
 * dialog cleared, a new AI context), staff get a system line, the version
 * is bumped and the cookie is forgotten; the next message starts a new chat.
 * A banned chat changes nothing and its cookie is KEPT (WC-14). Switched
 * off: nothing is stored (WC-6). No limiter: it writes at most once per chat.
 */
class EndWebChat
{
    public function __construct(
        private readonly ResolveWebChat $resolve,
        private readonly WebChatToken $token,
    ) {}

    /**
     * @return bool whether a chat was ended now
     */
    public function handle(): bool
    {
        if (! WebChatSwitch::isOpen()) {
            return false;
        }

        $resolved = $this->resolve->handle();

        if ($resolved->banned) {
            return false;
        }

        if ($resolved->chat === null) {
            if ($this->token->current() !== null) {
                $this->token->forget();
            }

            return false;
        }

        $ended = DB::transaction(function () use ($resolved): bool {
            $locked = WebChat::query()->whereKey($resolved->chat->id)->lockForUpdate()->first();

            if ($locked === null || $locked->isBanned() || $locked->isClosed()) {
                return false;
            }

            $locked->forceFill([
                'closed_at' => now(),
                'conversation_status' => ConversationStatus::Closed,
                'mode' => ChatMode::Bot,
                'dialog' => null,
            ]);
            $locked->resetAiContext();
            $locked->messages()->create(['author' => ChatAuthor::System, 'text' => __('telegram_inbox.web.ended_line', [], 'uz')]);
            $locked->save();

            WebChatVersion::bump($locked->id);

            return true;
        });

        if ($ended || $resolved->chat->isClosed()) {
            $this->token->forget();
        }

        return $ended;
    }
}
