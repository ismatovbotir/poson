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

    public function label(): string
    {
        return __("enums.telegram_message_kind.{$this->value}");
    }
}
