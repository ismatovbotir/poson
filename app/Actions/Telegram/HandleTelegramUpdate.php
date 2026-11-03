<?php

namespace App\Actions\Telegram;

use App\Jobs\AnswerBotCallback;
use App\Jobs\SendBotPrompt;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\BotLimits;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Support\Facades\DB;

/**
 * Entry point for every Telegram update (webhook and telegram:poll), plan
 * 14 v2 §5 and conditions 3–4. In one transaction:
 *
 *  1. insert the update_id into telegram_updates (insertOrIgnore): a
 *     duplicate means it was handled already → nothing else happens;
 *  2. non-private chats, updates without a sender and senders with
 *     is_bot are dropped here, before any other write (condition 4);
 *  3. my_chat_member: the user blocked (kicked) / unblocked (member) the
 *     bot → telegram_users.blocked_at set / cleared on an existing row;
 *  4. the kill switch (BotSwitch, read fresh) stops customer processing
 *     only: messages and callbacks are dropped while the bot is disabled;
 *  5. customer messages / callbacks go to processCustomer(): limits, then
 *     the registration state machine (AdvanceRegistration).
 *
 * Nothing is sent from here (outbound messages are queued jobs dispatched
 * after commit) and no payload is logged.
 */
class HandleTelegramUpdate
{
    public const INVALID = 'invalid';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    public const DISABLED = 'disabled';

    public const MEMBERSHIP = 'membership';

    public const PROCESSED = 'processed';

    /**
     * @param  array<mixed>  $payload  The decoded update
     * @return self::* What happened (for tests and the poll command output)
     */
    public function handle(array $payload): string
    {
        $update = UpdateParser::fromArray($payload);

        if ($update === null) {
            return self::INVALID;
        }

        return DB::transaction(function () use ($update): string {
            $inserted = TelegramUpdate::query()->insertOrIgnore(['update_id' => $update->updateId, 'created_at' => now()]);

            if ($inserted === 0) {
                return self::DUPLICATE;
            }

            if (! $update->isPrivate() || $update->fromIsBot || $update->fromId === null) {
                return self::IGNORED;
            }

            if ($update->type === UpdateParser::TYPE_MEMBER) {
                $this->updateMembership($update);

                return self::MEMBERSHIP;
            }

            if (! in_array($update->type, [UpdateParser::TYPE_MESSAGE, UpdateParser::TYPE_CALLBACK], true)) {
                return self::IGNORED;
            }

            if (! BotSwitch::isEnabled()) {
                return self::DISABLED;
            }

            $this->processCustomer($update);

            return self::PROCESSED;
        });
    }

    /**
     * The user blocked ("kicked") or restarted ("member") the bot. Only an
     * existing conversation is touched; no row is created here.
     */
    protected function updateMembership(UpdateParser $update): void
    {
        $blockedAt = match ($update->memberStatus) {
            'kicked' => now(),
            'member' => null,
            default => false,
        };

        if ($blockedAt === false) {
            return;
        }

        TelegramUser::query()->where('telegram_id', $update->fromId)->update(['blocked_at' => $blockedAt]);
    }

    /**
     * Customer messages and callbacks (B3): the telegram_users row is created
     * if needed (insertOrIgnore, at most BotLimits::NEW_USERS_PER_MINUTE new
     * rows a minute) and locked for the rest of the transaction (D4,
     * condition 3). Banned users are ignored silently; over the per-user
     * limits the update is dropped with one "rate_limited" notice per window
     * (condition 8). Everything else goes to AdvanceRegistration (which
     * hands registered users' messages to the inbox hook, B4).
     */
    protected function processCustomer(UpdateParser $update): void
    {
        $telegramId = (int) $update->fromId;

        if (! TelegramUser::query()->where('telegram_id', $telegramId)->exists()) {
            if (! BotLimits::allowNewUser()) {
                $this->answerCallback($update);

                return;
            }

            TelegramUser::query()->insertOrIgnore(['telegram_id' => $telegramId, 'created_at' => now(), 'updated_at' => now()]);
        }

        $user = TelegramUser::query()->where('telegram_id', $telegramId)->lockForUpdate()->first();

        if (! $user instanceof TelegramUser || $user->isBanned()) {
            $this->answerCallback($update);

            return;
        }

        $limited = BotLimits::hitUser($telegramId);

        if ($limited !== null) {
            if ($limited) {
                dispatch(new SendBotPrompt($user->id, ['rate_limited'], callbackQueryId: $update->callbackQueryId));
            } else {
                $this->answerCallback($update);
            }

            return;
        }

        // Profile fields as Telegram shows them now; a message also means the bot is not blocked.
        $user->fill([
            'username' => $update->username !== null && preg_match('/^[A-Za-z0-9_]{1,64}$/', $update->username) === 1 ? $update->username : null,
            'first_name' => BotText::clean($update->firstName) ?: null,
            'last_name' => BotText::clean($update->lastName) ?: null,
            'blocked_at' => null,
        ]);

        app(AdvanceRegistration::class)->handle($user, $update);

        if ($user->isDirty()) {
            $user->save();
        }
    }

    /**
     * A pressed inline button always gets its spinner stopped, even when the
     * update is otherwise dropped (banned, limits): nothing else happens.
     */
    protected function answerCallback(UpdateParser $update): void
    {
        if ($update->type === UpdateParser::TYPE_CALLBACK && $update->callbackQueryId !== null) {
            dispatch(new AnswerBotCallback($update->callbackQueryId));
        }
    }
}
