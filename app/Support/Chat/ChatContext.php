<?php

namespace App\Support\Chat;

use App\Enums\ChatChannel;

/**
 * What the engine knows about one incoming message (plan 28): the channel,
 * the visitor's language and the raw text. No person, no chat id: the engine
 * is pure and cannot tell who asks.
 */
final readonly class ChatContext
{
    public function __construct(
        public ChatChannel $channel,
        public string $locale,
        public string $text = '',
    ) {}

    public function normalized(): string
    {
        return ChatText::normalize($this->text);
    }
}
