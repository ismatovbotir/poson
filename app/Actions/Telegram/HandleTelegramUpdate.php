<?php

namespace App\Actions\Telegram;

use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\BotSwitch;
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
 *  5. customer messages / callbacks go to processCustomer() (B3 stub).
 *
 * Nothing is sent from here (outbound messages are queued jobs, B3) and no
 * payload is logged.
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
     * B3/B4 STUB: registration state machine (AdvanceRegistration under
     * lockForUpdate on telegram_users), inbox intake (StoreInboundMessage)
     * and callback handling go here. Deliberately a no-op in B2: the bot
     * stays disabled in production until B6 is reviewed.
     */
    protected function processCustomer(UpdateParser $update): void
    {
        // Intentionally empty until B3.
    }
}
