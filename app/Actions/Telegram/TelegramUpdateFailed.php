<?php

namespace App\Actions\Telegram;

use RuntimeException;

/**
 * Reported when handling a Telegram update failed (condition 3 / 18): only
 * the update_id and the original exception class, never the payload, the
 * original message (SQL bindings could hold user text) or a chained cause.
 */
class TelegramUpdateFailed extends RuntimeException
{
    public function __construct(public readonly ?int $updateId, public readonly string $exceptionClass)
    {
        parent::__construct('Telegram update '.($updateId ?? '?').' failed: '.$exceptionClass);
    }
}
