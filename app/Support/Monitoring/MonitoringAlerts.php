<?php

namespace App\Support\Monitoring;

use App\Services\Telegram\TelegramClient;
use App\Support\Secrets;
use App\Support\Telegram\TelegramLocale;
use App\Support\Telegram\TelegramMessage;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Monitoring alerts to the Telegram management chat (plan 17 §4). Sent
 * synchronously (the queue may be the broken part) with a short timeout;
 * failures are reported, never thrown. Skipped quietly when the bot or chat
 * isn't configured. Every value goes through Redactor (≤ 200 chars, MON-S11)
 * and e(). Messages are in the Telegram language.
 */
final class MonitoringAlerts
{
    public const CHAT = 'management';

    public const FIELD_LIMIT = 200;

    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public static function isConfigured(): bool
    {
        return app(TelegramClient::class)->isConfigured() && Secrets::isConfigured('telegram.chats.'.self::CHAT);
    }

    /**
     * @param  string  $problem  cron_dead | task_failed | queue_stuck | worker_silent
     * @param  array<string, scalar|null>  $values  label key => value
     * @return self::SENT|self::SKIPPED|self::FAILED
     */
    public static function problem(string $problem, array $values, bool $reminder = false, int $timeout = 5): string
    {
        return self::send('events', $problem, $values, $reminder, $timeout);
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return self::SENT|self::SKIPPED|self::FAILED
     */
    public static function recovered(string $problem, array $values = [], int $timeout = 5): string
    {
        return self::send('recovered', $problem, $values, false, $timeout);
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    private static function send(string $group, string $problem, array $values, bool $reminder, int $timeout): string
    {
        if (! self::isConfigured()) {
            return self::SKIPPED;
        }

        try {
            $locale = TelegramLocale::current();
            $lines = ['<b>'.e(__("telegram.monitoring.{$group}.{$problem}", [], $locale)).'</b>'];

            if ($reminder) {
                $lines[] = e(__('telegram.monitoring.reminder', [], $locale));
            }

            foreach ($values as $label => $value) {
                $text = Redactor::clean($value === null ? '' : (string) $value, self::FIELD_LIMIT);

                if ($text !== '') {
                    $lines[] = e(__("telegram.monitoring.labels.{$label}", [], $locale)).': '.e($text);
                }
            }

            $path = Route::has('admin.monitoring') ? route('admin.monitoring', absolute: false) : '/admin/monitoring';

            app(TelegramClient::class)->withTimeout($timeout)->sendMessage(
                (string) Secrets::get('telegram.chats.'.self::CHAT),
                implode("\n", $lines),
                TelegramMessage::adminButton($path, __('telegram.monitoring.open', [], $locale)),
            );

            return self::SENT;
        } catch (Throwable $exception) {
            report($exception);

            return self::FAILED;
        }
    }
}
