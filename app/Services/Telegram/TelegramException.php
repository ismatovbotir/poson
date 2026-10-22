<?php

namespace App\Services\Telegram;

use RuntimeException;

/**
 * A failed Telegram Bot API call. Carries only what callers need to decide
 * (HTTP status, retry_after, whether it timed out), never the request URL:
 * the bot token is part of it. The message is a fixed text, and the
 * original exception is not chained for the same reason.
 *
 * status 0 = no HTTP response (connection failure or timeout).
 */
class TelegramException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        public readonly bool $timedOut = false,
    ) {
        parent::__construct($status === 0
            ? ($timedOut ? 'Telegram API request timed out' : 'Telegram API connection failed')
            : "Telegram API error (HTTP {$status})");
    }

    /**
     * A short safe code for logs and activities: the HTTP status, "timeout" or "connection".
     */
    public function code(): string
    {
        return match (true) {
            $this->status > 0 => (string) $this->status,
            $this->timedOut => 'timeout',
            default => 'connection',
        };
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    /**
     * Retrying can't help: bad request (chat not found, bad HTML) or the bot was blocked / kicked.
     */
    public function isPermanent(): bool
    {
        return in_array($this->status, [400, 401, 403, 404], true);
    }
}
