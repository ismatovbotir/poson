<?php

namespace App\Console\Commands;

use App\Enums\TelegramUserState;
use App\Jobs\SyncMiniAppMenuButton;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\MiniApp\BotUsername;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Telegram Mini App chat menu buttons (plan 20 M5 §7, MA-23, MA-28,
 * MA-31). Refreshes the stored bot username from getMe() first, then:
 *
 *  - --chat=<telegram id>: one registered conversation;
 *  - --all: every registered, not blocked conversation, one queued
 *    SyncMiniAppMenuButton each, spread at BATCH per second (a 429 inside
 *    a job releases it for retry_after, the others carry on; 403 → blocked);
 *  - --reset: Telegram's default button instead of the Mini App one (with
 *    --chat or --all for those chats; alone, the bot-wide default, which
 *    is never a web_app button).
 */
#[Signature('telegram:mini-app-menu {--all : Every registered conversation} {--reset : Set Telegram\'s default menu button instead} {--chat= : One conversation, by its Telegram id}')]
#[Description('Sync or reset the Telegram Mini App menu button for one chat or every registered chat')]
class TelegramMiniAppMenu extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Sets the bot chat menu button: the Mini App "Catalog" button for customers who may use it (registered, not banned or blocked, Mini App switch on), Telegram\'s default for everyone else.
Use --chat=<telegram id> for one conversation, --all for every registered one (queued jobs on the default queue, spread out to respect Telegram limits), and --reset to put back the default button (alone: the bot-wide default only).
Run it after turning the Mini App switch on or off and after changing APP_URL. Side effects: Bot API calls (setChatMenuButton, getMe) and the stored bot username. Manual only, not scheduled.';

    /**
     * Jobs started per second by --all (Telegram allows about 30 calls a second).
     */
    public const BATCH = 20;

    public function handle(TelegramClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('The Telegram bot token is not configured.');

            return self::FAILURE;
        }

        $this->refreshUsername($client);

        $reset = (bool) $this->option('reset');
        $chat = $this->option('chat');

        if (is_string($chat) && $chat !== '') {
            return $this->one($chat, $reset);
        }

        if ($this->option('all')) {
            return $this->all($client, $reset);
        }

        if ($reset) {
            return $this->resetDefault($client) ? self::SUCCESS : self::FAILURE;
        }

        $this->error('Choose --chat=<telegram id>, --all or --reset.');

        return self::INVALID;
    }

    private function refreshUsername(TelegramClient $client): void
    {
        try {
            if (! BotUsername::remember($client->getMe()['username'] ?? null)) {
                $this->warn('The bot username from Telegram is not valid; order messages go without a button.');
            }
        } catch (TelegramException $exception) {
            $this->warn('Could not read the bot username (code '.$exception->code().').');
        }
    }

    private function one(string $chat, bool $reset): int
    {
        $user = preg_match('/\A[1-9]\d{0,19}\z/', $chat) === 1
            ? TelegramUser::query()->where('telegram_id', $chat)->registered()->first()
            : null;

        if (! $user instanceof TelegramUser) {
            $this->error('No registered conversation with this Telegram id.');

            return self::FAILURE;
        }

        dispatch(new SyncMiniAppMenuButton($user->id, $reset))->afterCommit();
        $this->info('Queued the menu button sync for 1 conversation.');

        return self::SUCCESS;
    }

    private function all(TelegramClient $client, bool $reset): int
    {
        if ($reset && ! $this->resetDefault($client)) {
            return self::FAILURE;
        }

        $queued = 0;

        TelegramUser::query()
            ->where('state', TelegramUserState::Registered)
            ->whereNotNull('registered_at')
            ->whereNull('blocked_at')
            ->select('id')
            ->chunkById(500, function (Collection $users) use (&$queued, $reset): void {
                foreach ($users as $user) {
                    dispatch(new SyncMiniAppMenuButton($user->id, $reset))
                        ->delay(now()->addSeconds(intdiv($queued, self::BATCH)))
                        ->afterCommit();
                    $queued++;
                }
            });

        $this->info("Queued the menu button sync for {$queued} conversations.");

        return self::SUCCESS;
    }

    /**
     * The bot-wide default: always Telegram's default button (MA-28).
     */
    private function resetDefault(TelegramClient $client): bool
    {
        try {
            $client->setChatMenuButton(null, null);
        } catch (TelegramException $exception) {
            $this->error('Telegram refused the default menu button (code '.$exception->code().').');

            return false;
        }

        $this->info('The bot-wide menu button is Telegram\'s default.');

        return true;
    }
}
