<?php

namespace App\Console\Commands;

use App\Actions\Telegram\ConfigureTelegramWebhook;
use App\Actions\Telegram\TelegramBotException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Set, delete or inspect the Telegram webhook from the console (plan 14 v2
 * §5). "set" uses ConfigureTelegramWebhook (APP_URL + fixed path, https
 * only, audited without an actor). "info" prints whether the URL matches,
 * never the URL itself or Telegram's error text.
 */
#[Signature('telegram:webhook {action : set, delete or info}')]
#[Description('Set, delete or inspect the Telegram bot webhook')]
class TelegramWebhook extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Manages the Telegram bot webhook: "set" registers APP_URL + /api/telegram/webhook with the webhook secret (https only), "delete" removes it, "info" shows whether it matches and how many updates wait.
Run it at deploy time or when the bot page is not reachable; the bot page in admin does the same.
Side effects: set and delete change the webhook at Telegram and write an audit row. Manual only, not scheduled.';

    public function handle(ConfigureTelegramWebhook $configure, TelegramClient $client): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'set' => $this->done(fn () => $configure->set(null, null), __('admin.telegram_bot.flash.webhook_set')),
                'delete' => $this->done(fn () => $configure->delete(null, null), __('admin.telegram_bot.flash.webhook_deleted')),
                'info' => $this->showInfo($client),
                default => $this->invalid(),
            };
        } catch (TelegramBotException $exception) {
            $this->error($exception->translated());

            return self::FAILURE;
        }
    }

    private function done(callable $callback, string $message): int
    {
        $callback();
        $this->info($message);

        return self::SUCCESS;
    }

    private function showInfo(TelegramClient $client): int
    {
        if (! $client->isConfigured()) {
            throw new TelegramBotException('no_token');
        }

        try {
            $info = $client->getWebhookInfo();
        } catch (TelegramException $exception) {
            throw new TelegramBotException('telegram_error', apiCode: $exception->code());
        }

        $expected = ConfigureTelegramWebhook::expectedUrl();
        $url = is_string($info['url'] ?? null) ? $info['url'] : '';
        $lastError = $info['last_error_date'] ?? null;

        $this->table(['', ''], [
            ['url', $url === '' ? 'none' : ($expected !== null && hash_equals($expected, $url) ? 'matches APP_URL' : 'DIFFERENT from APP_URL')],
            ['pending_update_count', (string) ($info['pending_update_count'] ?? 0)],
            ['last_error_date', is_int($lastError) ? date('Y-m-d H:i:s', $lastError) : '-'],
            ['max_connections', (string) ($info['max_connections'] ?? '-')],
        ]);

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Unknown action. Use set, delete or info.');

        return self::INVALID;
    }
}
