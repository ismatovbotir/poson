<?php

namespace App\Console\Commands;

use App\Actions\Telegram\CheckTelegramWebhook;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Scheduled webhook health check (plan 14 v2, TG-38); see CheckTelegramWebhook.
 * Fails (recorded by RunRecorder) only when Telegram could not be reached.
 */
#[Signature('telegram:check-webhook')]
#[Description('Check the Telegram webhook and alert the management chat about problems')]
class TelegramCheckWebhook extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Asks Telegram for the webhook state and alerts the management chat when the URL differs from APP_URL, deliveries failed in the last 15 minutes, more than 100 updates wait, or the token is refused (401). While the bot is disabled it only alerts about an unexpected webhook URL.
Alerts are deduplicated, repeated hourly and followed by a "recovered" message; they never include the URL or Telegram error text.
Side effects: Telegram alerts only. Scheduled every 15 minutes; safe to run by hand.';

    public function handle(CheckTelegramWebhook $check): int
    {
        $result = $check->handle();

        $this->line($result);

        return $result === CheckTelegramWebhook::UNREACHABLE ? self::FAILURE : self::SUCCESS;
    }
}
