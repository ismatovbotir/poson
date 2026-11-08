<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Enums\ChatMode;
use App\Enums\ConversationStatus;
use App\Enums\Role;
use App\Enums\TelegramDirection;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Jobs\DeliverBotMessage;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * A staff reply in a bot conversation (plan 14 v2 §9, plan 21 A2, T21-12).
 *
 * Under the telegram_users row lock: policy "reply" against the row as it is
 * now, then the state a policy cannot refuse for an Admin (T21-25): not
 * registered, banned, blocked the bot, bot off or no token. Then the per-user
 * atomic limit, shared with ResendTelegramMessage.
 *
 * A manager's first reply on an unassigned conversation assigns it to them
 * (provisional owner decision) by a conditional update; when that loses to
 * another manager the reply is refused. An Admin's reply leaves it unassigned.
 * A reply on a closed conversation reopens it, with an Activity row. The stored
 * message is plain text delivered by DeliverBotMessage after commit, which sends
 * no parse_mode.
 *
 * Idempotent per token: a repeat of the same text with the same token (double
 * Ctrl+Enter, even as two serialized requests) within ONCE_SECONDS stores
 * nothing, uses up no limit and returns null. The token is claimed as the last
 * step under the row lock, holding sha1(text), so a failed attempt can be retried
 * at once and a different text with a reused token is sent.
 *
 * A failed attempt keeps its limit hit on a cache store outside the database, and
 * loses it with the rollback on the database store; nothing gives it back by hand.
 */
class SendTelegramReply
{
    public const MAX_LENGTH = 4000;

    /**
     * Telegram's own limit, counted in UTF-16 code units.
     */
    private const TELEGRAM_MAX_UNITS = 4096;

    public const PER_MINUTE = 20;

    public const LIMIT_KEY = 'telegram-inbox-reply:';

    public const ONCE_SECONDS = 10;

    public function __construct(private readonly TelegramClient $client) {}

    /**
     * @return TelegramMessage|null null: a true duplicate of what was already sent
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(TelegramUser $conversation, User $actor, string $text, ?string $token = null): ?TelegramMessage
    {
        $text = $this->clean($text);
        $hash = sha1($text);
        $committed = false;

        try {
            return DB::transaction(function () use ($conversation, $actor, $text, $token, $hash, &$committed): ?TelegramMessage {
                $locked = TelegramUser::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

                Gate::forUser($actor)->authorize('reply', $locked);

                $refusal = $this->refusal($locked);

                if ($refusal !== null) {
                    throw new InboxRefused($refusal);
                }

                // Under the row lock, so two submits of one conversation cannot both pass.
                if ($token !== null && Cache::get($this->onceKey($locked->id, $actor, $token)) === $hash) {
                    return null;
                }

                $this->hitLimit($actor);

                $this->assignOnFirstReply($locked, $actor);
                $this->reopenIfClosed($locked, $actor);

                $message = TelegramMessage::query()->create([
                    'telegram_user_id' => $locked->id,
                    'direction' => TelegramDirection::Out,
                    'kind' => TelegramMessageKind::Text,
                    'text' => $text,
                    'user_id' => $actor->id,
                    'status' => TelegramMessageStatus::Pending,
                ]);

                // A staff reply takes the conversation over from the chat bot (plan 28, CB-4).
                TelegramUser::query()->whereKey($locked->id)->update(['last_message_at' => now(), 'chat_mode' => ChatMode::Manager, 'handoff_at' => now(), 'last_staff_reply_at' => now()]);

                DB::afterCommit(function () use (&$committed): void {
                    $committed = true;
                });

                dispatch(new DeliverBotMessage($message->id));

                // Last step: nothing above can leave it claimed. A database cache store rolls back with the
                // transaction, and any other store is cleared in the catch below if the commit fails.
                if ($token !== null) {
                    Cache::put($this->onceKey($locked->id, $actor, $token), $hash, self::ONCE_SECONDS);
                }

                return $message;
            });
        } catch (Throwable $failure) {
            if (! $committed && $token !== null) {
                $this->releaseToken((int) $conversation->getKey(), $actor, $token);
            }

            throw $failure;
        }
    }

    /**
     * Why nothing can be delivered in this conversation, or null. Show uses it
     * to disable the reply box; the actions enforce it.
     */
    public function refusal(TelegramUser $conversation): ?string
    {
        return match (true) {
            ! $conversation->isRegistered() => 'unregistered',
            $conversation->isBanned() => 'banned',
            $conversation->blocked_at !== null => 'blocked',
            ! $this->client->isConfigured() || ! BotSwitch::isEnabled() => 'bot_off',
            default => null,
        };
    }

    /**
     * Count one send of the actor. Atomic: the hit itself decides.
     *
     * @throws InboxRefused
     */
    public function hitLimit(User $actor): void
    {
        if (RateLimiter::hit(self::LIMIT_KEY.$actor->id, 60) > self::PER_MINUTE) {
            throw new InboxRefused('rate_limited');
        }
    }

    /**
     * Give back a hit for a call that turned out to do nothing.
     */
    public function releaseLimit(User $actor): void
    {
        RateLimiter::decrement(self::LIMIT_KEY.$actor->id);
    }

    /**
     * Whether this token was already used for a reply: the second of two
     * serialized submits arrives with the draft already cleared.
     */
    public function alreadySent(int $conversationId, User $actor, string $token): bool
    {
        return Cache::has($this->onceKey($conversationId, $actor, $token));
    }

    /**
     * Free the token of an attempt that did not commit. A cache failure here is
     * only reported, so it cannot mask the failure that brought us here.
     */
    private function releaseToken(int $conversationId, User $actor, string $token): void
    {
        try {
            Cache::forget($this->onceKey($conversationId, $actor, $token));
        } catch (Throwable $cacheFailure) {
            report($cacheFailure);
        }
    }

    private function onceKey(int $conversationId, User $actor, string $token): string
    {
        return 'telegram-inbox-reply-once:'.$conversationId.':'.$actor->id.':'.sha1($token);
    }

    /**
     * @throws InboxRefused
     */
    private function clean(string $text): string
    {
        $clean = preg_replace('/[^\P{Cc}\t\n]/u', '', str_replace("\r\n", "\n", $text));
        $clean = is_string($clean) ? trim($clean) : '';
        $units = strlen((string) mb_convert_encoding($clean, 'UTF-16LE', 'UTF-8')) / 2;

        if ($clean === '' || mb_strlen($clean) > self::MAX_LENGTH || $units > self::TELEGRAM_MAX_UNITS) {
            throw new InboxRefused('invalid_text');
        }

        return $clean;
    }

    /**
     * @throws InboxRefused the conversation went to another manager meanwhile
     */
    private function assignOnFirstReply(TelegramUser $locked, User $actor): void
    {
        if ($locked->manager_id !== null || $actor->hasRole(Role::Admin)) {
            return;
        }

        $claimed = TelegramUser::query()->whereKey($locked->id)->whereNull('manager_id')->update(['manager_id' => $actor->id]);

        if ($claimed === 0) {
            if (TelegramUser::query()->whereKey($locked->id)->value('manager_id') !== $actor->id) {
                throw new InboxRefused('taken');
            }

            return;
        }

        $locked->activities()->create([
            'user_id' => $actor->id,
            'type' => ActivityType::Assignment,
            'meta' => ['action' => 'first_reply', 'from_id' => null, 'to_id' => $actor->id],
            'created_at' => now(),
        ]);
    }

    private function reopenIfClosed(TelegramUser $locked, User $actor): void
    {
        $reopened = TelegramUser::query()
            ->whereKey($locked->id)
            ->where('conversation_status', ConversationStatus::Closed)
            ->update(['conversation_status' => ConversationStatus::Open, 'closed_at' => null]);

        if ($reopened !== 1) {
            return;
        }

        $locked->activities()->create([
            'user_id' => $actor->id,
            'type' => ActivityType::StatusChange,
            'meta' => ['from' => ConversationStatus::Closed->value, 'to' => ConversationStatus::Open->value, 'via' => 'reply'],
            'created_at' => now(),
        ]);
    }
}
