<?php

namespace App\Support\Telegram\Bot;

use App\Enums\TelegramMessageKind;

/**
 * One incoming Telegram update, reduced to the safe fields the bot needs
 * (plan 14 v2 §5, readonly DTO). Built by fromArray() from the decoded
 * webhook / getUpdates payload; never throws on odd input.
 *
 * - Only private chats count (isPrivate(): chat.type private and chat.id ===
 *   from.id); everything else is dropped by HandleTelegramUpdate before any
 *   write except update_id (condition 4).
 * - Never keeps a file_id / file_unique_id or a vcard (D9, condition 5):
 *   media are reduced to their kind and size / type / duration params.
 * - Messages sent via an inline bot or carrying buttons, stories, giveaways
 *   and paid media are kind "other" (TG-22), and so are venues (they also
 *   carry a location, but a title / address the copy would repeat verbatim).
 * - file_name is reduced to its base name without format (bidi, zero-width)
 *   characters; mime_type is kept only when it looks like type/subtype
 *   (B4-S5); media_group_id only when it is a short token.
 */
final readonly class UpdateParser
{
    public const TYPE_MESSAGE = 'message';

    public const TYPE_CALLBACK = 'callback_query';

    public const TYPE_MEMBER = 'my_chat_member';

    public const TYPE_OTHER = 'other';

    public const TEXT_LIMIT = 4096;

    public const CALLBACK_DATA_LIMIT = 64;

    /**
     * Message keys checked in this order to find the kind (photo before document etc.).
     *
     * @var array<string, TelegramMessageKind>
     */
    private const KINDS = [
        'text' => TelegramMessageKind::Text,
        'photo' => TelegramMessageKind::Photo,
        'animation' => TelegramMessageKind::Animation,
        'document' => TelegramMessageKind::Document,
        'video' => TelegramMessageKind::Video,
        'video_note' => TelegramMessageKind::VideoNote,
        'voice' => TelegramMessageKind::Voice,
        'audio' => TelegramMessageKind::Audio,
        'sticker' => TelegramMessageKind::Sticker,
        'location' => TelegramMessageKind::Location,
        'contact' => TelegramMessageKind::Contact,
    ];

    /**
     * Message keys that always make the message "other" (TG-22).
     */
    private const OTHER_MARKERS = ['via_bot', 'reply_markup', 'story', 'giveaway', 'giveaway_winners', 'paid_media', 'venue'];

    /**
     * Media params kept (never file_id / file_unique_id / thumbnails).
     */
    private const MEDIA_PARAMS = ['file_size', 'mime_type', 'file_name', 'duration', 'width', 'height'];

    public const MIME_PATTERN = '/^[\w.+-]+\/[\w.+-]+$/D';

    public const FILE_NAME_LIMIT = 255;

    /**
     * @param  array<string, int|string>  $media  Safe media params (see MEDIA_PARAMS)
     */
    public function __construct(
        public int $updateId,
        public string $type,
        public ?string $chatType = null,
        public ?int $chatId = null,
        public ?int $fromId = null,
        public bool $fromIsBot = false,
        public ?string $username = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $languageCode = null,
        public ?int $messageId = null,
        public ?TelegramMessageKind $kind = null,
        public ?string $text = null,
        public array $media = [],
        public bool $isForwarded = false,
        public ?string $contactPhone = null,
        public ?int $contactUserId = null,
        public ?string $callbackQueryId = null,
        public ?string $callbackData = null,
        public ?string $memberStatus = null,
        public ?string $mediaGroupId = null,
    ) {}

    /**
     * Null when the payload has no integer update_id.
     *
     * @param  array<mixed>  $update
     */
    public static function fromArray(array $update): ?self
    {
        $updateId = $update['update_id'] ?? null;

        if (! is_int($updateId) || $updateId < 0) {
            return null;
        }

        if (is_array($update['message'] ?? null)) {
            return self::fromMessage($updateId, $update['message']);
        }

        if (is_array($update['callback_query'] ?? null)) {
            return self::fromCallback($updateId, $update['callback_query']);
        }

        if (is_array($update['my_chat_member'] ?? null)) {
            $member = $update['my_chat_member'];

            return new self(
                $updateId,
                self::TYPE_MEMBER,
                ...self::chatAndSender($member['chat'] ?? null, $member['from'] ?? null),
                memberStatus: self::string($member['new_chat_member']['status'] ?? null, 20),
            );
        }

        return new self($updateId, self::TYPE_OTHER);
    }

    /**
     * A private chat with a person (not a group, channel or bot).
     */
    public function isPrivate(): bool
    {
        return $this->chatType === 'private' && $this->chatId !== null && $this->chatId === $this->fromId;
    }

    /**
     * @param  array<mixed>  $message
     */
    private static function fromMessage(int $updateId, array $message): self
    {
        $kind = self::kindOf($message);
        $text = $kind === TelegramMessageKind::Text ? self::string($message['text'] ?? null, self::TEXT_LIMIT) : self::string($message['caption'] ?? null, self::TEXT_LIMIT);
        $contact = $kind === TelegramMessageKind::Contact && is_array($message['contact'] ?? null) ? $message['contact'] : [];

        return new self(
            $updateId,
            self::TYPE_MESSAGE,
            ...self::chatAndSender($message['chat'] ?? null, $message['from'] ?? null),
            messageId: is_int($message['message_id'] ?? null) ? $message['message_id'] : null,
            kind: $kind,
            text: $text,
            media: self::mediaParams($kind, $message),
            isForwarded: isset($message['forward_origin']) || isset($message['forward_from']) || isset($message['forward_date']),
            contactPhone: self::string($contact['phone_number'] ?? null, 32),
            contactUserId: is_int($contact['user_id'] ?? null) ? $contact['user_id'] : null,
            mediaGroupId: is_string($message['media_group_id'] ?? null) && preg_match('/^[\w-]{1,32}$/D', $message['media_group_id']) === 1 ? $message['media_group_id'] : null,
        );
    }

    /**
     * @param  array<mixed>  $callback
     */
    private static function fromCallback(int $updateId, array $callback): self
    {
        $message = is_array($callback['message'] ?? null) ? $callback['message'] : [];

        return new self(
            $updateId,
            self::TYPE_CALLBACK,
            ...self::chatAndSender($message['chat'] ?? null, $callback['from'] ?? null),
            messageId: is_int($message['message_id'] ?? null) ? $message['message_id'] : null,
            callbackQueryId: self::string($callback['id'] ?? null, 64),
            callbackData: self::rawCallbackData($callback['data'] ?? null),
        );
    }

    /**
     * Named constructor arguments for the chat and sender fields.
     *
     * @return array{chatType: ?string, chatId: ?int, fromId: ?int, fromIsBot: bool, username: ?string, firstName: ?string, lastName: ?string, languageCode: ?string}
     */
    private static function chatAndSender(mixed $chat, mixed $from): array
    {
        $chat = is_array($chat) ? $chat : [];
        $from = is_array($from) ? $from : [];

        return [
            'chatType' => self::string($chat['type'] ?? null, 20),
            'chatId' => is_int($chat['id'] ?? null) ? $chat['id'] : null,
            'fromId' => is_int($from['id'] ?? null) ? $from['id'] : null,
            'fromIsBot' => ($from['is_bot'] ?? false) !== false,
            'username' => self::string($from['username'] ?? null, 64),
            'firstName' => self::string($from['first_name'] ?? null, 128),
            'lastName' => self::string($from['last_name'] ?? null, 128),
            'languageCode' => self::string($from['language_code'] ?? null, 10),
        ];
    }

    /**
     * @param  array<mixed>  $message
     */
    private static function kindOf(array $message): TelegramMessageKind
    {
        foreach (self::OTHER_MARKERS as $marker) {
            if (isset($message[$marker])) {
                return TelegramMessageKind::Other;
            }
        }

        foreach (self::KINDS as $key => $kind) {
            if (isset($message[$key])) {
                return $kind;
            }
        }

        return TelegramMessageKind::Other;
    }

    /**
     * @param  array<mixed>  $message
     * @return array<string, int|string>
     */
    private static function mediaParams(TelegramMessageKind $kind, array $message): array
    {
        if (in_array($kind, [TelegramMessageKind::Text, TelegramMessageKind::Location, TelegramMessageKind::Contact, TelegramMessageKind::Other], true)) {
            return [];
        }

        $item = $message[$kind->value] ?? null;

        // Photos come as a list of sizes: keep the largest.
        if ($kind === TelegramMessageKind::Photo && is_array($item) && array_is_list($item)) {
            $item = end($item);
        }

        if (! is_array($item)) {
            return [];
        }

        $params = [];

        foreach (self::MEDIA_PARAMS as $param) {
            $value = $item[$param] ?? null;

            $clean = match (true) {
                is_int($value) => $value >= 0 && ! in_array($param, ['mime_type', 'file_name'], true) ? $value : null,
                ! is_string($value) => null,
                $param === 'file_name' => self::fileName($value),
                $param === 'mime_type' => self::mimeType($value),
                default => null,
            };

            if ($clean !== null) {
                $params[$param] = $clean;
            }
        }

        return $params;
    }

    /**
     * Base name only (no "../", no folders), format characters (bidi
     * overrides, zero-width) removed, ≤ FILE_NAME_LIMIT; null when nothing is left.
     */
    private static function fileName(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $value);
        $parts = preg_split('#[/\\\\]#', $value) ?: [];
        $name = trim((string) end($parts));

        return $name === '' || $name === '.' || $name === '..' ? null : mb_substr($name, 0, self::FILE_NAME_LIMIT);
    }

    private static function mimeType(string $value): ?string
    {
        $value = strtolower(trim($value));

        return strlen($value) <= 100 && preg_match(self::MIME_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Callback data exactly as sent (not trimmed or cleaned, so the strict
     * allow-list sees tampering such as a trailing newline); null when it is
     * not a string or longer than Telegram's 64 bytes.
     */
    private static function rawCallbackData(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= self::CALLBACK_DATA_LIMIT ? $value : null;
    }

    /**
     * A trimmed string without control characters, cut to $limit; null when empty.
     */
    private static function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
