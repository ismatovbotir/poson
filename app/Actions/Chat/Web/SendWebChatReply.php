<?php

namespace App\Actions\Chat\Web;

use App\Actions\Telegram\InboxRefused;
use App\Enums\ChatAuthor;
use App\Enums\ChatMode;
use App\Enums\Role;
use App\Models\User;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Support\Chat\WebChatInput;
use App\Support\Chat\WebChatVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A staff reply in a website chat (plan 28 C5c, WC-12). Limits and the
 * double-submit guard are hit before the transaction (WC-3). Under the
 * web_chats row lock: policy "reply", then what a policy cannot refuse for
 * an Admin: a banned, closed or expired chat is refused (no reply that
 * pretends to be delivered). Text is cleaned (Cc/Cf removed) and cut to
 * chatbot.web.message_max. The chat goes to manager mode, the counter is
 * cleared, a new AI context starts, and a non-Admin's first reply on an
 * unassigned chat assigns it to them. The version is bumped after commit,
 * so the visitor's next poll shows the reply.
 */
class SendWebChatReply
{
    public const PER_MINUTE = 20;

    public const LIMIT_KEY = 'web-chat-reply:';

    public const ONCE_SECONDS = 5;

    /**
     * @return WebChatMessage|null null: a repeat of the same text just sent
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(WebChat $chat, User $actor, mixed $text): ?WebChatMessage
    {
        Gate::forUser($actor)->authorize('reply', $chat);

        $clean = WebChatInput::clean($text);

        if ($clean === null) {
            throw new InboxRefused('invalid_text');
        }

        if (RateLimiter::hit(self::LIMIT_KEY.$actor->id, 60) > self::PER_MINUTE) {
            throw new InboxRefused('rate_limited');
        }

        if (! Cache::add('web-chat-reply-once:'.$chat->id.':'.$actor->id.':'.sha1($clean), true, self::ONCE_SECONDS)) {
            return null;
        }

        return DB::transaction(function () use ($chat, $actor, $clean): WebChatMessage {
            $locked = WebChat::query()->whereKey($chat->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('reply', $locked);

            if ($refusal = $this->refusal($locked)) {
                throw new InboxRefused($refusal);
            }

            if ($locked->manager_id === null && ! $actor->hasRole(Role::Admin)) {
                $locked->manager_id = $actor->id;
            }

            $now = now();
            $locked->forceFill([
                'mode' => ChatMode::Manager,
                'handoff_at' => $locked->handoff_at ?? $now,
                'last_message_at' => $now,
                'last_staff_reply_at' => $now,
                'unread_count' => 0,
            ]);
            $locked->resetAiContext();
            $locked->save();

            $message = $locked->messages()->create(['author' => ChatAuthor::Staff, 'text' => $clean, 'user_id' => $actor->id]);

            WebChatVersion::bump($locked->id);

            return $message;
        });
    }

    /**
     * Why no reply can be delivered, or null. Show disables the box with it.
     */
    public function refusal(WebChat $chat): ?string
    {
        return match (true) {
            $chat->isBanned() => 'banned',
            $chat->isClosed() => 'closed',
            $chat->isExpired() => 'expired',
            default => null,
        };
    }
}
