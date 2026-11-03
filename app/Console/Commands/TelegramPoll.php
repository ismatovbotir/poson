<?php

namespace App\Console\Commands;

use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\TelegramUpdateFailed;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Local development without a public https URL (plan 14 v2 D2): long-polls
 * getUpdates and feeds every update to the same HandleTelegramUpdate as the
 * webhook. Refuses in production. Telegram rejects getUpdates while a
 * webhook is set (delete it first with telegram:webhook delete, test bot only).
 */
#[Signature('telegram:poll {--once : Fetch one batch and stop} {--timeout=25 : Long-poll seconds}')]
#[Description('Development only: receive Telegram bot updates by long polling')]
class TelegramPoll extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Development only: long-polls Telegram getUpdates and handles every update exactly like the webhook (dedupe, private chats only, kill switch).
Use it locally with a test bot when there is no public https URL; the bot must have no webhook (telegram:webhook delete).
Refuses to run in production. Side effects: stores update ids and processes bot updates. Manual only, not scheduled.';

    public function handle(TelegramClient $client, HandleTelegramUpdate $handleUpdate): int
    {
        if (app()->isProduction()) {
            $this->error('telegram:poll is for development only; production uses the webhook.');

            return self::FAILURE;
        }

        if (! $client->isConfigured()) {
            $this->error(__('admin.telegram_bot.errors.no_token'));

            return self::FAILURE;
        }

        $timeout = max(1, min(50, (int) $this->option('timeout')));
        $polling = $client->withTimeout($timeout + 10);
        $offset = 0;

        do {
            $previousOffset = $offset;

            try {
                $updates = $polling->getUpdates($offset, $timeout, ConfigureTelegramWebhook::ALLOWED_UPDATES);
            } catch (TelegramException $exception) {
                $this->error(__('admin.telegram_bot.errors.telegram_error', ['code' => $exception->code()]));

                if ($exception->status === 409) {
                    $this->line('A webhook is set for this bot: run `php artisan telegram:webhook delete` first (test bot only).');
                }

                return self::FAILURE;
            }

            foreach ($updates as $update) {
                $updateId = $update['update_id'] ?? null;

                if (! is_int($updateId)) {
                    // Can't be acknowledged or deduplicated: skip it.
                    continue;
                }

                $offset = max($offset, $updateId + 1);

                try {
                    $this->line($updateId.': '.$handleUpdate->handle($update));
                } catch (Throwable $exception) {
                    report(new TelegramUpdateFailed($updateId, $exception::class));
                }
            }
            if ($updates !== [] && $offset === $previousOffset) {
                $this->error('Telegram returned updates without a valid update_id; stopping.');

                return self::FAILURE;
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
