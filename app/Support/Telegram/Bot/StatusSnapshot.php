<?php

namespace App\Support\Telegram\Bot;

use App\Actions\Telegram\CheckTelegramWebhook;
use App\Models\MonitoringState;
use Throwable;

/**
 * The status panel's snapshot is display-only (plan 21): it never feeds
 * SetBotEnabled, BotChecklist or any send decision.
 *
 * clearVerdicts() runs after a committed change of the bot token or a chat id
 * (next to InboxChat::forget()): the old verdicts describe another bot or
 * chat, so they are reset to unknown. checked_at stays, a missing row is not
 * created, and a failed clear is reported and never fails the saved change.
 */
final class StatusSnapshot
{
    /**
     * Fields that describe the previous token or chat.
     *
     * @return array<string, mixed>
     */
    public static function clearedFields(): array
    {
        return [
            'webhook_match' => null,
            'last_error_at' => null,
            'pending' => null,
            'get_me' => 'unknown',
            'chat' => 'unknown',
            'chat_key' => null,
            'code' => null,
        ];
    }

    public static function clearVerdicts(): void
    {
        try {
            $state = MonitoringState::query()->find(CheckTelegramWebhook::SNAPSHOT_KEY);

            if ($state === null) {
                return;
            }

            $state->forceFill(['meta' => [...($state->meta ?? []), ...self::clearedFields()]])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
