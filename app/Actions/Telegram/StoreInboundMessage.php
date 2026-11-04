<?php

namespace App\Actions\Telegram;

use App\Enums\ConversationStatus;
use App\Enums\TelegramDirection;
use App\Enums\TelegramForwardStatus;
use App\Enums\TelegramMessageKind;
use App\Enums\TelegramMessageStatus;
use App\Events\Telegram\TelegramInboxMessage;
use App\Jobs\DeliverBotMessage;
use App\Jobs\ForwardTelegramMedia;
use App\Jobs\SendBotPrompt;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Support\Secrets;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\Bot\UpdateParser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Inbox intake for a registered customer's message (plan 14 v2 §5–6, B4).
 * Called by AdvanceRegistration inside HandleTelegramUpdate's transaction
 * with the telegram_users row locked (B4-S10; the lock is taken again here
 * and the inbox counters re-read from it, so direct callers with an older
 * model are safe too).
 *
 *  - text: stored as is (≤ 4096), status received;
 *  - forwardable media (TG-22 allow-list): metadata only (sanitized params,
 *    never a file_id), caption ≤ CAPTION_LIMIT, forward_status pending →
 *    ForwardTelegramMedia (after commit);
 *  - contact, via_bot / buttons, polls, stories …: kind "other", nothing of
 *    the content kept (no phone or name, B4-S6), not forwarded; the customer
 *    gets "send text, photo or file" at most once per UNSUPPORTED_MINUTES.
 *
 * Every inbound row raises unread_count and last_*_at and reopens a closed
 * conversation. Text messages raise TelegramInboxMessage (telegram.notify_inbox
 * on, ≤ 1 per conversation per ALERT_MINUTES via last_alert_at). Text and
 * media get one auto-ack per ACK_MINUTES (Cache::add per user), a stored
 * outbound message delivered by DeliverBotMessage; it never echoes the text.
 *
 * All jobs and the alert are dispatched after commit. Nothing is logged.
 */
class StoreInboundMessage
{
    public const CAPTION_LIMIT = 1024;

    public const ACK_MINUTES = 30;

    public const ALERT_MINUTES = 10;

    public const UNSUPPORTED_MINUTES = 10;

    public function handle(TelegramUser $user, UpdateParser $update): ?TelegramMessage
    {
        if ($update->type !== UpdateParser::TYPE_MESSAGE || $update->kind === null || $update->messageId === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $update): ?TelegramMessage {
            $this->refreshInboxState($user);

            // Telegram ids are unique per chat: a repeated message (new update_id) is not stored twice.
            $exists = TelegramMessage::query()
                ->where('telegram_user_id', $user->id)
                ->where('direction', TelegramDirection::In)
                ->where('telegram_message_id', $update->messageId)
                ->exists();

            if ($exists) {
                return null;
            }

            $message = TelegramMessage::query()->create($this->attributes($user, $update));
            $now = now();

            $user->forceFill([
                'unread_count' => $user->unread_count + 1,
                'last_message_at' => $now,
                'last_inbound_at' => $now,
            ]);

            if ($user->conversation_status === ConversationStatus::Closed) {
                $user->forceFill(['conversation_status' => ConversationStatus::Open, 'closed_at' => null]);
            }

            if ($message->kind === TelegramMessageKind::Other) {
                $this->unsupported($user);
            } else {
                $this->accepted($user, $message);
            }

            $user->save();

            return $message;
        });
    }

    /**
     * Lock the row and take its inbox counters from the database (the given
     * model may be older than the lock), keeping any other unsaved changes
     * of the caller (HandleTelegramUpdate's profile fields).
     */
    private function refreshInboxState(TelegramUser $user): void
    {
        $locked = TelegramUser::query()->whereKey($user->id)->lockForUpdate()->first();

        if ($locked === null) {
            return;
        }

        $fields = ['unread_count', 'conversation_status', 'closed_at', 'last_alert_at', 'last_message_at', 'last_inbound_at'];

        $user->setRawAttributes(array_merge($user->getAttributes(), array_intersect_key($locked->getAttributes(), array_flip($fields))));
        $user->syncOriginalAttributes($fields);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(TelegramUser $user, UpdateParser $update): array
    {
        $kind = $update->kind;
        $forwardable = $kind?->isForwardable() === true;
        $storedKind = $kind === TelegramMessageKind::Text || $forwardable ? $kind : TelegramMessageKind::Other;

        $text = match (true) {
            $storedKind === TelegramMessageKind::Text => $update->text,
            $forwardable && $kind->hasCaption() && $update->text !== null => mb_substr($update->text, 0, self::CAPTION_LIMIT),
            default => null,
        };

        return [
            'telegram_user_id' => $user->id,
            'direction' => TelegramDirection::In,
            'kind' => $storedKind,
            'text' => $text,
            'media' => $forwardable && $update->media !== [] ? $update->media : null,
            'media_group_id' => $forwardable ? $update->mediaGroupId : null,
            'telegram_message_id' => $update->messageId,
            'status' => TelegramMessageStatus::Received,
            'forward_status' => $forwardable ? TelegramForwardStatus::Pending : null,
        ];
    }

    private function accepted(TelegramUser $user, TelegramMessage $message): void
    {
        if ($message->forward_status === TelegramForwardStatus::Pending) {
            dispatch(new ForwardTelegramMedia($message->id, $user->id));
        }

        if ($message->kind === TelegramMessageKind::Text && $this->alertDue($user)) {
            $user->forceFill(['last_alert_at' => now()]);
            TelegramInboxMessage::dispatch($message->id);
        }

        if (Cache::add('telegram-inbox-ack:'.$user->id, true, self::ACK_MINUTES * 60)) {
            $ack = TelegramMessage::query()->create([
                'telegram_user_id' => $user->id,
                'direction' => TelegramDirection::Out,
                'kind' => TelegramMessageKind::Text,
                'text' => __('telegram_bot.inbox.auto_ack', [], BotText::locale($user)),
                'status' => TelegramMessageStatus::Pending,
            ]);

            dispatch(new DeliverBotMessage($ack->id));
        }
    }

    private function unsupported(TelegramUser $user): void
    {
        if (Cache::add('telegram-inbox-unsupported:'.$user->id, true, self::UNSUPPORTED_MINUTES * 60)) {
            dispatch(new SendBotPrompt($user->id, ['inbox.unsupported']));
        }
    }

    private function alertDue(TelegramUser $user): bool
    {
        if (Secrets::get('telegram.notify_inbox') === false) {
            return false;
        }

        return $user->last_alert_at === null || $user->last_alert_at->lte(now()->subMinutes(self::ALERT_MINUTES));
    }
}
