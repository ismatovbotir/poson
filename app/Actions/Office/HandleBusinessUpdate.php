<?php

namespace App\Actions\Office;

use App\Enums\ActivityType;
use App\Enums\BusinessAuthor;
use App\Enums\BusinessChatKind;
use App\Jobs\FetchBusinessConnection;
use App\Models\Activity;
use App\Models\BusinessChat;
use App\Models\BusinessChatAnalysis;
use App\Models\BusinessConnection;
use App\Models\BusinessMessage;
use App\Models\Lead;
use App\Support\Chat\ChatRedactor;
use App\Support\Office\OfficeLimits;
use App\Support\Office\OfficeSettings;
use App\Support\Phone;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\Bot\BusinessUpdate;
use Illuminate\Support\Carbon;

/**
 * One Telegram Business update (plan 41 §3), called by HandleTelegramUpdate
 * inside its transaction right after the update_id dedupe and BEFORE any
 * customer-bot check (VO-2): nothing here touches telegram_users,
 * BotSwitch, BotLimits, the registration or any send job. Nothing is
 * logged (VO-5).
 *
 *  1. business_connection → UpsertBusinessConnection (allow-list, rights, audit).
 *  2. Everything else: dropped while office.ingest_enabled is off; an
 *     unknown connection id → one FetchBusinessConnection (OfficeLimits)
 *     and dropped; dropped unless the connection is allowed (stored flag
 *     AND the owner is on the allow-list now) and enabled (VO-1); dropped
 *     unless the chat is a private chat.
 *  3. Author with the connection's stored owner id (BusinessUpdate::author):
 *     owner / client / else dropped (business bot echoes ignored in phase 1).
 *  4. The chat row: created only for a new message, within the new-chat
 *     flood limits (insertOrIgnore), then locked for the transaction (VO-3).
 *  5. By kind: unmarked → counters, times and the name stub only (VO-4);
 *     ignored → nothing; employee → last_message_at; client → the message
 *     is stored (≤ 500 a day), edits update it, deletes null text and media
 *     and the derived analysis texts (VO-18), a client's own shared contact
 *     fills an empty phone, and the chat is due for analysis after the
 *     debounce.
 *  6. Deletes are matched by (connection, chat.id) only (VO-13).
 */
class HandleBusinessUpdate
{
    public const CONNECTION = 'connection';

    public const INGEST_OFF = 'ingest_off';

    public const UNKNOWN_CONNECTION = 'unknown_connection';

    public const NOT_ALLOWED = 'not_allowed';

    public const DROPPED = 'dropped';

    public const LIMITED = 'limited';

    public const STUB = 'stub';

    public const STORED = 'stored';

    public const NOTHING = 'nothing';

    public function __construct(protected UpsertBusinessConnection $upsert) {}

    /**
     * @return self::* What happened (tests and the poll command)
     */
    public function handle(BusinessUpdate $update): string
    {
        if ($update->type === BusinessUpdate::TYPE_CONNECTION) {
            $this->upsert->handle([
                'connectionId' => $update->connectionId,
                'ownerId' => $update->ownerId,
                'userChatId' => $update->userChatId,
                'isEnabled' => $update->isEnabled === true,
                'rights' => $update->rights,
                'connectionDate' => $update->connectionDate,
            ]);

            return self::CONNECTION;
        }

        if (! OfficeSettings::ingestEnabled()) {
            return self::INGEST_OFF;
        }

        if ($update->connectionId === null) {
            return self::DROPPED;
        }

        $connection = BusinessConnection::query()->where('connection_id', $update->connectionId)->first();

        if ($connection === null) {
            if (OfficeLimits::allowConnectionFetch($update->connectionId)) {
                dispatch(new FetchBusinessConnection($update->connectionId));
            }

            return self::UNKNOWN_CONNECTION;
        }

        // The stored flag and the live allow-list (an .env override changes without a recompute).
        if (! $connection->isActive() || ! OfficeSettings::isOwner($connection->owner_telegram_id)) {
            return self::NOT_ALLOWED;
        }

        if (! $update->isPrivateChat()) {
            return self::DROPPED;
        }

        if ($update->type === BusinessUpdate::TYPE_DELETED) {
            return $this->deleted($connection, $update);
        }

        $author = $update->author($connection->owner_telegram_id);

        if ($author === null || $update->messageId === null) {
            return self::DROPPED;
        }

        $chat = $this->chat($connection, $update);

        if ($chat === null) {
            return $update->type === BusinessUpdate::TYPE_MESSAGE ? self::LIMITED : self::NOTHING;
        }

        return match ($chat->kind) {
            BusinessChatKind::Unmarked => $this->unmarked($chat, $update, $author),
            BusinessChatKind::Ignored => self::NOTHING,
            BusinessChatKind::Employee => $this->employee($chat, $update),
            BusinessChatKind::Client => $this->client($chat, $update, $author),
        };
    }

    /**
     * The locked chat row; a new one only for a new message within the flood limits.
     */
    protected function chat(BusinessConnection $connection, BusinessUpdate $update): ?BusinessChat
    {
        $query = fn () => BusinessChat::query()->where('business_connection_id', $connection->id)->where('telegram_chat_id', $update->chatId);

        if (! $query()->exists()) {
            if ($update->type !== BusinessUpdate::TYPE_MESSAGE || ! OfficeLimits::allowNewChat()) {
                return null;
            }

            BusinessChat::query()->insertOrIgnore([
                'business_connection_id' => $connection->id,
                'telegram_chat_id' => $update->chatId,
                'kind' => BusinessChatKind::Unmarked->value,
                'unread_count' => 0,
                'analyses_today' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $query()->lockForUpdate()->first();
    }

    /**
     * An attention stub: counters, times, the counterpart's first name and username. No text, media, phone or ids (VO-4).
     */
    protected function unmarked(BusinessChat $chat, BusinessUpdate $update, BusinessAuthor $author): string
    {
        if ($update->type !== BusinessUpdate::TYPE_MESSAGE) {
            return self::NOTHING;
        }

        $this->profile($chat, $update);

        if ($author === BusinessAuthor::Client) {
            $chat->unread_count++;
            $chat->first_inbound_at ??= now();
            $chat->last_inbound_at = now();
        } else {
            $chat->last_owner_at = now();
        }

        $chat->last_message_at = now();
        $chat->save();

        return self::STUB;
    }

    protected function employee(BusinessChat $chat, BusinessUpdate $update): string
    {
        if ($update->type === BusinessUpdate::TYPE_MESSAGE) {
            $chat->last_message_at = now();
            $chat->save();
        }

        return self::NOTHING;
    }

    protected function client(BusinessChat $chat, BusinessUpdate $update, BusinessAuthor $author): string
    {
        $this->profile($chat, $update);

        if ($update->type === BusinessUpdate::TYPE_EDITED) {
            return $this->edited($chat, $update);
        }

        if ($author === BusinessAuthor::Client) {
            $chat->unread_count++;
            $chat->first_inbound_at ??= now();
            $chat->last_inbound_at = now();

            // Only the client's own contact (user_id = the chat), never one they forwarded.
            if ($chat->phone === null && $update->contactPhone !== null && $update->contactUserId === $update->chatId) {
                $chat->phone = Phone::normalizeAny($update->contactPhone);
            }
        } else {
            $chat->last_owner_at = now();
        }

        $chat->last_message_at = now();

        if (! OfficeLimits::allowStoredMessage($chat->id)) {
            $chat->save();

            return self::LIMITED;
        }

        $exists = $chat->messages()->where('telegram_message_id', $update->messageId)->exists();

        if (! $exists) {
            $chat->messages()->create([
                'telegram_message_id' => $update->messageId,
                'author' => $author,
                'kind' => $update->kind,
                'text' => $update->text,
                'media' => $this->media($update),
                'media_group_id' => $update->mediaGroupId,
                'reply_to_message_id' => $update->replyToMessageId,
                'sent_at' => $update->date !== null ? Carbon::createFromTimestamp($update->date) : now(),
            ]);
        }

        $this->due($chat);
        $chat->save();

        return self::STORED;
    }

    /**
     * An edit of a stored message: new text / media and edited_at. Never-stored messages create nothing (VO-4).
     */
    protected function edited(BusinessChat $chat, BusinessUpdate $update): string
    {
        $message = $chat->messages()->where('telegram_message_id', $update->messageId)->whereNull('deleted_at')->first();

        if (! $message instanceof BusinessMessage) {
            $chat->save();

            return self::NOTHING;
        }

        $message->fill([
            'kind' => $update->kind,
            'text' => $update->text,
            'media' => $this->media($update),
            'edited_at' => $update->editDate !== null ? Carbon::createFromTimestamp($update->editDate) : now(),
        ])->save();

        $this->due($chat);
        $chat->save();

        return self::STORED;
    }

    /**
     * Deleted in Telegram: text and media of stored messages nulled at once, and the summary,
     * next step and draft of every analysis that included them (VO-18). Scoped to this connection.
     */
    protected function deleted(BusinessConnection $connection, BusinessUpdate $update): string
    {
        if ($update->deletedIds === []) {
            return self::NOTHING;
        }

        $chat = BusinessChat::query()->where('business_connection_id', $connection->id)->where('telegram_chat_id', $update->chatId)->lockForUpdate()->first();

        if (! $chat instanceof BusinessChat || ! $chat->isClient()) {
            return self::NOTHING;
        }

        $first = $chat->messages()->whereIn('telegram_message_id', $update->deletedIds)->min('id');

        if ($first === null) {
            return self::NOTHING;
        }

        $chat->messages()->whereIn('telegram_message_id', $update->deletedIds)->update(['text' => null, 'media' => null, 'deleted_at' => now()]);

        $affected = BusinessChatAnalysis::query()->where('business_chat_id', $chat->id)->where('up_to_message_id', '>=', (int) $first);
        $analysisIds = (clone $affected)->pluck('id')->all();
        $affected->update(['topic' => null, 'summary' => null, 'next_step' => null, 'draft_reply' => null]);

        // L1: the topic copied into a lead's AiSummary activity goes too.
        $leadIds = BusinessChatAnalysis::query()->whereIn('id', $analysisIds)->whereNotNull('lead_id')->distinct()->pluck('lead_id')->push($chat->lead_id)->filter()->unique()->all();

        if ($analysisIds !== [] && $leadIds !== []) {
            Activity::query()->where('type', ActivityType::AiSummary->value)
                ->where('subject_type', (new Lead)->getMorphClass())->whereIn('subject_id', $leadIds)
                ->get(['id', 'meta'])
                ->filter(fn (Activity $activity): bool => in_array($activity->meta['analysis_id'] ?? null, $analysisIds, true))
                ->each(fn (Activity $activity) => $activity->forceFill(['meta' => ['analysis_id' => $activity->meta['analysis_id']]])->save());
        }

        $this->due($chat);
        $chat->save();

        return self::STORED;
    }

    /**
     * The counterpart's first name and username from the chat object (A1), cleaned and cut to the column size.
     */
    protected function profile(BusinessChat $chat, BusinessUpdate $update): void
    {
        $name = mb_substr(BotText::clean($update->chatFirstName), 0, 64);

        if ($name !== '') {
            $chat->first_name = $name;
        }

        if ($update->chatUsername !== null) {
            $chat->username = $update->chatUsername;
        }
    }

    /**
     * @return array<string, int|string>|null
     */
    protected function media(BusinessUpdate $update): ?array
    {
        $media = $update->media;

        // M2: a file name can carry a phone, an email or a card: stored only redacted.
        if (isset($media['file_name'])) {
            $name = ChatRedactor::clean((string) $media['file_name'], 80);

            if ($name === '' || $name === ChatRedactor::MASK) {
                unset($media['file_name']);
            } else {
                $media['file_name'] = $name;
            }
        }

        if ($update->isVenue) {
            $media['venue'] = 1;
        }

        return $media === [] ? null : $media;
    }

    protected function due(BusinessChat $chat): void
    {
        $chat->analysis_due_at = now()->addMinutes(OfficeSettings::debounceMinutes());
    }
}
