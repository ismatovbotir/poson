<?php

namespace App\Actions\Chat\Web;

/**
 * What PostVisitorMessage did, for the widget to show (C5b maps each status
 * to its chatbot.widget.* text). Never carries text, a token or a hash.
 */
final readonly class PostedMessage
{
    /**
     * Stored, and the bot answered (synchronously).
     */
    public const ANSWERED = 'answered';

    /**
     * Stored for staff (manager mode).
     */
    public const QUEUED = 'queued';

    /**
     * Nothing to do: not a string, empty after cleaning, or the same text again within seconds.
     */
    public const IGNORED = 'ignored';

    /**
     * Over a rate limit: nothing stored.
     */
    public const LIMITED = 'limited';

    /**
     * The chat is switched off, banned or gone: nothing stored.
     */
    public const UNAVAILABLE = 'unavailable';

    /**
     * Manager mode with too many messages in a row without a staff reply: "please wait", nothing stored.
     */
    public const WAIT = 'wait';

    /**
     * @param  self::*  $status
     */
    public function __construct(
        public string $status,
        public ?int $chatId = null,
    ) {}

    public function stored(): bool
    {
        return in_array($this->status, [self::ANSWERED, self::QUEUED], true);
    }
}
