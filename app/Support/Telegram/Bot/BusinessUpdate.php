<?php

namespace App\Support\Telegram\Bot;

use App\Enums\BusinessAuthor;
use App\Enums\TelegramMessageKind;

/**
 * One Telegram Business update (plan 41 §3, VO-2), reduced to safe fields:
 * business_connection, business_message, edited_business_message and
 * deleted_business_messages. A separate type from UpdateParser on purpose:
 * HandleTelegramUpdate returns for it right after the dedupe, so it can
 * never reach the customer bot (isPrivate, BotSwitch, BotLimits,
 * telegram_users, AdvanceRegistration). Built by fromUpdate(); never throws
 * on odd input; never keeps a file_id, vcard or thumbnail.
 *
 * ──────────────────────────────────────────────────────────────────────
 * SHAPE ASSUMPTIONS (built from the Bot API docs, no live capture yet —
 * verify on the first real connection, see
 * tests/Fixtures/telegram/business/README.md). Every one of them lives in
 * this class only:
 *
 *  A1 business_message / edited_business_message: "chat" is the private
 *     chat between the business account and the counterpart, so chat.id is
 *     the CLIENT's user id, whoever wrote the message.
 *  A2 "from" is the client for an incoming message and the business
 *     account owner (BusinessConnection.user.id) for a message the owner
 *     sent from their own app. author() decides with the STORED owner id of
 *     the connection, never with a message field (VO-1).
 *  A3 A message the bot sent on the owner's behalf carries
 *     sender_business_bot (phase 2); phase 1 ignores it.
 *  A4 deleted_business_messages: chat is the same private chat (A1) and
 *     message_ids are the ids business_message carried; "the bot may not
 *     have access to the chat", so only chat.id is read.
 *  A5 business_connection: user is the owner, user_chat_id the owner's
 *     private chat with the bot, rights an optional BusinessBotRights
 *     object of optional "true" flags (only RIGHTS are kept), is_enabled
 *     false on disconnect. getBusinessConnection returns the same object.
 *  A6 Messages between the owner and the bot itself are ordinary "message"
 *     updates without business_connection_id (customer bot, unchanged).
 * ──────────────────────────────────────────────────────────────────────
 */
final readonly class BusinessUpdate
{
    public const TYPE_CONNECTION = 'business_connection';

    public const TYPE_MESSAGE = 'business_message';

    public const TYPE_EDITED = 'edited_business_message';

    public const TYPE_DELETED = 'deleted_business_messages';

    /**
     * The update keys, in the order they are checked (also the extra setWebhook allowed_updates).
     */
    public const TYPES = [self::TYPE_CONNECTION, self::TYPE_MESSAGE, self::TYPE_EDITED, self::TYPE_DELETED];

    /**
     * Business connection id (Bot API String): a short token only (VO-13).
     */
    public const CONNECTION_ID_PATTERN = '/^[\w-]{1,128}$/D';

    /**
     * BusinessBotRights flags that are kept (VO-13); everything else is dropped.
     */
    public const RIGHTS = ['can_reply', 'can_read_messages', 'can_delete_sent_messages', 'can_delete_all_messages'];

    public const MAX_DELETED_IDS = 100;

    /**
     * @param  array<string, int|string>  $media  Safe media params (UpdateParser::content())
     * @param  list<int>  $deletedIds
     * @param  array<string, bool>  $rights
     */
    public function __construct(
        public int $updateId,
        public string $type,
        public ?string $connectionId = null,
        public ?string $chatType = null,
        public ?int $chatId = null,
        public ?string $chatFirstName = null,
        public ?string $chatUsername = null,
        public ?int $fromId = null,
        public bool $fromIsBot = false,
        public bool $sentByBusinessBot = false,
        public ?int $messageId = null,
        public ?TelegramMessageKind $kind = null,
        public ?string $text = null,
        public array $media = [],
        public ?string $mediaGroupId = null,
        public ?int $replyToMessageId = null,
        public ?string $contactPhone = null,
        public ?int $contactUserId = null,
        public bool $isVenue = false,
        public ?int $date = null,
        public ?int $editDate = null,
        public array $deletedIds = [],
        public ?int $ownerId = null,
        public ?int $userChatId = null,
        public ?bool $isEnabled = null,
        public array $rights = [],
        public ?int $connectionDate = null,
    ) {}

    /**
     * The DTO for a business update, or null when the update is none of the four types.
     *
     * @param  array<mixed>  $update
     */
    public static function fromUpdate(int $updateId, array $update): ?self
    {
        if (is_array($update[self::TYPE_CONNECTION] ?? null)) {
            return self::fromConnection($updateId, $update[self::TYPE_CONNECTION]);
        }

        foreach ([self::TYPE_MESSAGE, self::TYPE_EDITED] as $type) {
            if (is_array($update[$type] ?? null)) {
                return self::fromMessage($updateId, $type, $update[$type]);
            }
        }

        if (is_array($update[self::TYPE_DELETED] ?? null)) {
            return self::fromDeleted($updateId, $update[self::TYPE_DELETED]);
        }

        foreach (self::TYPES as $type) {
            if (array_key_exists($type, $update)) {
                // A business key with a non-object value: still a business update (never the customer bot), with nothing in it.
                return new self($updateId, $type);
            }
        }

        return null;
    }

    /**
     * BusinessConnection fields (also the getBusinessConnection result, A5).
     *
     * @param  array<mixed>  $connection
     * @return array{connectionId: ?string, ownerId: ?int, userChatId: ?int, isEnabled: bool, rights: array<string, bool>, connectionDate: ?int}
     */
    public static function connectionFields(array $connection): array
    {
        $user = is_array($connection['user'] ?? null) ? $connection['user'] : [];
        $rights = is_array($connection['rights'] ?? null) ? $connection['rights'] : [];
        $kept = [];

        foreach (self::RIGHTS as $right) {
            if (($rights[$right] ?? false) === true) {
                $kept[$right] = true;
            }
        }

        // Older Bot API versions had a top-level can_reply instead of rights.can_reply.
        if (($connection['can_reply'] ?? false) === true) {
            $kept['can_reply'] = true;
        }

        return [
            'connectionId' => self::connectionId($connection['id'] ?? null),
            'ownerId' => self::positiveInt($user['id'] ?? null),
            'userChatId' => is_int($connection['user_chat_id'] ?? null) ? $connection['user_chat_id'] : null,
            'isEnabled' => ($connection['is_enabled'] ?? false) === true,
            'rights' => $kept,
            'connectionDate' => self::positiveInt($connection['date'] ?? null),
        ];
    }

    /**
     * Who wrote the message, decided with the connection's STORED owner id (A2, VO-1):
     * owner, client (from = the chat's counterpart), or null (dropped: a bot, the
     * business bot itself, or a sender that is neither).
     */
    public function author(int $storedOwnerId): ?BusinessAuthor
    {
        if ($this->sentByBusinessBot || $this->fromIsBot || $this->fromId === null || $this->chatId === null) {
            return null;
        }

        if ($this->fromId === $storedOwnerId) {
            return BusinessAuthor::Owner;
        }

        return $this->fromId === $this->chatId ? BusinessAuthor::Client : null;
    }

    /**
     * A private chat with one person (A1). Groups and channels are never stored.
     */
    public function isPrivateChat(): bool
    {
        return $this->chatType === 'private' && $this->chatId !== null && $this->chatId > 0;
    }

    public function isMessage(): bool
    {
        return in_array($this->type, [self::TYPE_MESSAGE, self::TYPE_EDITED], true);
    }

    /**
     * @param  array<mixed>  $connection
     */
    private static function fromConnection(int $updateId, array $connection): self
    {
        $fields = self::connectionFields($connection);

        return new self(
            $updateId,
            self::TYPE_CONNECTION,
            connectionId: $fields['connectionId'],
            ownerId: $fields['ownerId'],
            userChatId: $fields['userChatId'],
            isEnabled: $fields['isEnabled'],
            rights: $fields['rights'],
            connectionDate: $fields['connectionDate'],
        );
    }

    /**
     * @param  array<mixed>  $message
     */
    private static function fromMessage(int $updateId, string $type, array $message): self
    {
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $content = UpdateParser::content($message);
        $reply = is_array($message['reply_to_message'] ?? null) ? $message['reply_to_message'] : [];

        return new self(
            $updateId,
            $type,
            connectionId: self::connectionId($message['business_connection_id'] ?? null),
            chatType: UpdateParser::cleanString($chat['type'] ?? null, 20),
            chatId: is_int($chat['id'] ?? null) ? $chat['id'] : null,
            chatFirstName: UpdateParser::cleanString($chat['first_name'] ?? null, 128),
            chatUsername: self::username($chat['username'] ?? null),
            fromId: is_int($from['id'] ?? null) ? $from['id'] : null,
            fromIsBot: ($from['is_bot'] ?? false) !== false,
            sentByBusinessBot: isset($message['sender_business_bot']),
            messageId: self::positiveInt($message['message_id'] ?? null),
            kind: $content['kind'],
            text: $content['text'],
            media: $content['media'],
            mediaGroupId: $content['mediaGroupId'],
            replyToMessageId: self::positiveInt($reply['message_id'] ?? null),
            contactPhone: $content['contactPhone'],
            contactUserId: $content['contactUserId'],
            isVenue: isset($message['venue']),
            date: self::positiveInt($message['date'] ?? null),
            editDate: self::positiveInt($message['edit_date'] ?? null),
        );
    }

    /**
     * @param  array<mixed>  $deleted
     */
    private static function fromDeleted(int $updateId, array $deleted): self
    {
        $chat = is_array($deleted['chat'] ?? null) ? $deleted['chat'] : [];
        $ids = [];

        foreach (array_slice(is_array($deleted['message_ids'] ?? null) ? $deleted['message_ids'] : [], 0, self::MAX_DELETED_IDS) as $id) {
            if (is_int($id) && $id > 0) {
                $ids[$id] = $id;
            }
        }

        return new self(
            $updateId,
            self::TYPE_DELETED,
            connectionId: self::connectionId($deleted['business_connection_id'] ?? null),
            chatType: UpdateParser::cleanString($chat['type'] ?? null, 20),
            chatId: is_int($chat['id'] ?? null) ? $chat['id'] : null,
            deletedIds: array_values($ids),
        );
    }

    private static function connectionId(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::CONNECTION_ID_PATTERN, $value) === 1 ? $value : null;
    }

    private static function username(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $value) === 1 ? $value : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
