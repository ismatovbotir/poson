<?php

namespace App\Support\Telegram\Bot;

use App\Enums\TelegramMessageKind;

/**
 * One update of a Telegram group or supergroup, reduced to the safe fields
 * the group ingest needs (plan 44 §2, OG-1). A separate DTO type, built by
 * UpdateParser::fromArray() right after the business branch, only when
 *
 *  - the update's first known key is message, edited_message,
 *    my_chat_member or chat_member, and
 *  - its chat.type is group or supergroup (an integer, negative chat.id).
 *
 * HandleTelegramUpdate hands it to HandleGroupUpdate and returns BEFORE
 * isPrivate, BotSwitch, BotLimits, telegram_users and the registration: it
 * can never reach the customer bot. Channels, private chats and every other
 * update type stay out (the parser turns them into TYPE_OTHER).
 *
 * Tolerant (T2-3): a missing chat, a non-integer id, unknown keys, a null
 * text or an oversized field never throw; an unusable update is null here
 * (UpdateParser makes it TYPE_OTHER), oversized strings are cut. Never keeps
 * a file_id, a vcard or a forward origin; text, caption and media params go
 * through UpdateParser::content().
 *
 * A person (sender, joined or left member, target of a chat_member) is
 * ['id' => int, 'isBot' => bool, 'firstName' => ?string, 'username' => ?string].
 */
final readonly class GroupUpdate
{
    public const TYPE_MESSAGE = 'message';

    public const TYPE_EDITED = 'edited_message';

    public const TYPE_BOT_MEMBER = 'my_chat_member';

    public const TYPE_MEMBER = 'chat_member';

    public const TYPES = [self::TYPE_MESSAGE, self::TYPE_EDITED, self::TYPE_BOT_MEMBER, self::TYPE_MEMBER];

    public const CHAT_TYPES = ['group', 'supergroup'];

    public const TITLE_LIMIT = 128;

    public const NAME_LIMIT = 64;

    public const MAX_NEW_MEMBERS = 10;

    public const STATUSES = ['creator', 'administrator', 'member', 'restricted', 'left', 'kicked'];

    /**
     * The update keys that decide the type, checked in this order: the first present one is the type.
     * A channel post or a callback in front of a group key keeps the update out of the group branch.
     */
    private const PRIMARY_KEYS = ['message', 'edited_message', 'channel_post', 'edited_channel_post', 'callback_query', 'my_chat_member', 'chat_member'];

    /**
     * @param  array<string, int|string>  $media  Safe media params (never a file_id)
     * @param  array{id: int, isBot: bool, firstName: ?string, username: ?string}|null  $from
     * @param  list<array{id: int, isBot: bool, firstName: ?string, username: ?string}>  $newMembers
     * @param  array{id: int, isBot: bool, firstName: ?string, username: ?string}|null  $leftMember
     * @param  array{id: int, isBot: bool, firstName: ?string, username: ?string}|null  $memberUser  The user of a chat_member update
     */
    public function __construct(
        public int $updateId,
        public string $type,
        public int $chatId,
        public string $chatType,
        public ?string $title = null,
        public ?string $chatUsername = null,
        public ?array $from = null,
        public ?int $messageId = null,
        public ?int $date = null,
        public ?int $editDate = null,
        public ?TelegramMessageKind $kind = null,
        public ?string $text = null,
        public array $media = [],
        public ?string $mediaGroupId = null,
        public ?int $replyToMessageId = null,
        public ?string $contactPhone = null,
        public ?int $contactUserId = null,
        public bool $hasSenderChat = false,
        public bool $isAutomaticForward = false,
        public array $newMembers = [],
        public ?array $leftMember = null,
        public ?int $migrateToChatId = null,
        public ?int $migrateFromChatId = null,
        public ?string $memberStatus = null,
        public ?array $memberUser = null,
    ) {}

    /**
     * Whether this update belongs to the group branch (no parsing yet).
     *
     * @param  array<mixed>  $update
     */
    public static function concerns(array $update): bool
    {
        foreach (self::PRIMARY_KEYS as $key) {
            if (! is_array($update[$key] ?? null)) {
                continue;
            }

            $chat = $update[$key]['chat'] ?? null;

            return in_array($key, self::TYPES, true) && is_array($chat) && in_array($chat['type'] ?? null, self::CHAT_TYPES, true);
        }

        return false;
    }

    /**
     * Null when the update is not usable (no integer negative chat id, a business message): the parser makes it TYPE_OTHER.
     *
     * @param  array<mixed>  $update
     */
    public static function fromUpdate(int $updateId, array $update): ?self
    {
        foreach (self::TYPES as $type) {
            if (is_array($update[$type] ?? null)) {
                $body = $update[$type];

                break;
            }
        }

        if (! isset($type, $body) || ! is_array($body['chat'] ?? null)) {
            return null;
        }

        $chat = $body['chat'];
        $chatId = $chat['id'] ?? null;

        if (! is_int($chatId) || $chatId >= 0 || ! in_array($chat['type'] ?? null, self::CHAT_TYPES, true)) {
            return null;
        }

        $common = [
            'updateId' => $updateId,
            'type' => $type,
            'chatId' => $chatId,
            'chatType' => $chat['type'],
            'title' => self::cut(BotText::clean(is_string($chat['title'] ?? null) ? $chat['title'] : null), self::TITLE_LIMIT),
            'chatUsername' => self::username($chat['username'] ?? null),
        ];

        return match ($type) {
            self::TYPE_MESSAGE, self::TYPE_EDITED => self::fromMessage($common, $body),
            default => self::fromMember($common, $body),
        };
    }

    /**
     * @param  array<string, mixed>  $common
     * @param  array<mixed>  $message
     */
    private static function fromMessage(array $common, array $message): ?self
    {
        // A message carrying a business connection id is never a group message.
        if (array_key_exists('business_connection_id', $message)) {
            return null;
        }

        // A migration key with an unusable (non-negative or non-integer) chat id is not a message at all.
        foreach (['migrate_to_chat_id', 'migrate_from_chat_id'] as $key) {
            if (array_key_exists($key, $message) && self::chatRef($message[$key]) === null) {
                return null;
            }
        }

        $content = UpdateParser::content($message);
        // Bidi and zero-width format characters are removed from text and caption (newlines are kept).
        $content['text'] = $content['text'] === null ? null : (preg_replace('/\p{Cf}/u', '', $content['text']) ?: null);
        $newMembers = [];

        if (is_array($message['new_chat_members'] ?? null)) {
            foreach (array_slice(array_values($message['new_chat_members']), 0, self::MAX_NEW_MEMBERS) as $member) {
                $person = self::person($member);

                if ($person !== null) {
                    $newMembers[] = $person;
                }
            }
        }

        return new self(
            ...$common,
            from: self::person($message['from'] ?? null),
            messageId: is_int($message['message_id'] ?? null) ? $message['message_id'] : null,
            date: self::time($message['date'] ?? null),
            editDate: self::time($message['edit_date'] ?? null),
            kind: $content['kind'],
            text: $content['text'],
            media: $content['media'],
            mediaGroupId: $content['mediaGroupId'],
            replyToMessageId: is_array($message['reply_to_message'] ?? null) && is_int($message['reply_to_message']['message_id'] ?? null) ? $message['reply_to_message']['message_id'] : null,
            contactPhone: $content['contactPhone'],
            contactUserId: $content['contactUserId'],
            hasSenderChat: isset($message['sender_chat']),
            isAutomaticForward: ($message['is_automatic_forward'] ?? false) === true,
            newMembers: $newMembers,
            leftMember: self::person($message['left_chat_member'] ?? null),
            migrateToChatId: self::chatRef($message['migrate_to_chat_id'] ?? null),
            migrateFromChatId: self::chatRef($message['migrate_from_chat_id'] ?? null),
        );
    }

    /**
     * my_chat_member (the bot's own status) and chat_member (another member's).
     *
     * @param  array<string, mixed>  $common
     * @param  array<mixed>  $change
     */
    private static function fromMember(array $common, array $change): self
    {
        $new = is_array($change['new_chat_member'] ?? null) ? $change['new_chat_member'] : [];
        $status = $new['status'] ?? null;

        return new self(
            ...$common,
            from: self::person($change['from'] ?? null),
            date: self::time($change['date'] ?? null),
            memberStatus: is_string($status) && in_array($status, self::STATUSES, true) ? $status : null,
            memberUser: self::person($new['user'] ?? null),
        );
    }

    /**
     * @return array{id: int, isBot: bool, firstName: ?string, username: ?string}|null
     */
    public static function person(mixed $user): ?array
    {
        if (! is_array($user) || ! is_int($user['id'] ?? null) || $user['id'] <= 0) {
            return null;
        }

        return [
            'id' => $user['id'],
            'isBot' => ($user['is_bot'] ?? false) === true,
            'firstName' => self::cut(BotText::clean(is_string($user['first_name'] ?? null) ? $user['first_name'] : null), self::NAME_LIMIT) ?: null,
            'username' => self::username($user['username'] ?? null),
        ];
    }

    private static function username(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $value) === 1 ? $value : null;
    }

    private static function chatRef(mixed $value): ?int
    {
        return is_int($value) && $value < 0 ? $value : null;
    }

    private static function time(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    private static function cut(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }

    public function fromId(): ?int
    {
        return $this->from['id'] ?? null;
    }

    public function isMessage(): bool
    {
        return in_array($this->type, [self::TYPE_MESSAGE, self::TYPE_EDITED], true);
    }

    /**
     * A service message (joins, leaves, migration, title change, …): no text and no content kind.
     */
    public function isService(): bool
    {
        return $this->type === self::TYPE_MESSAGE && $this->kind === TelegramMessageKind::Other && $this->text === null;
    }
}
