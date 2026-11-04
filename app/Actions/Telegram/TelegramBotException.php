<?php

namespace App\Actions\Telegram;

use RuntimeException;

/**
 * A bot setup action refused or failed (plan 14 v2 B2). The reason is a
 * fixed code; translated() is the translated text for the admin screen and
 * console. Never carries a URL, token or Telegram error text.
 *
 * Reasons: not_https, no_secret, no_token, not_ready, chat_unavailable, telegram_error.
 */
class TelegramBotException extends RuntimeException
{
    /**
     * @param  list<string>  $failing  Checklist items that failed (not_ready)
     */
    public function __construct(public readonly string $reason, public readonly array $failing = [], public readonly ?string $apiCode = null)
    {
        parent::__construct("Telegram bot action refused: {$reason}".($apiCode !== null ? " ({$apiCode})" : ''));
    }

    /**
     * Translated text for the admin screen.
     */
    public function translated(): string
    {
        return __('admin.telegram_bot.errors.'.$this->reason, ['code' => $this->apiCode ?? '']);
    }
}
