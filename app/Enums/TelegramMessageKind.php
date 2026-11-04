<?php

namespace App\Enums;

/**
 * Kind of a bot message; only metadata of non-text kinds is stored.
 */
enum TelegramMessageKind: string
{
    case Text = 'text';
    case Photo = 'photo';
    case Document = 'document';
    case Voice = 'voice';
    case Audio = 'audio';
    case Video = 'video';
    case VideoNote = 'video_note';
    case Animation = 'animation';
    case Sticker = 'sticker';
    case Location = 'location';
    case Contact = 'contact';
    case Other = 'other';

    /**
     * Kinds copied to the team chat (TG-22 allow-list). Text stays in the
     * inbox; contact and other (via_bot, buttons, stories, polls …) are never copied.
     */
    public function isForwardable(): bool
    {
        return in_array($this, [
            self::Photo, self::Document, self::Video, self::Audio, self::Voice,
            self::VideoNote, self::Animation, self::Sticker, self::Location,
        ], true);
    }

    /**
     * Kinds whose copy carries a caption (B4-S4): always sent explicitly,
     * '' when empty, so Telegram never re-uses the original caption entities.
     */
    public function hasCaption(): bool
    {
        return in_array($this, [self::Photo, self::Document, self::Video, self::Audio, self::Voice, self::Animation], true);
    }

    public function label(): string
    {
        return __("enums.telegram_message_kind.{$this->value}");
    }
}
