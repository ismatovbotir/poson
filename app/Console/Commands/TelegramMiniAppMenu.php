<?php

namespace App\Console\Commands;

use App\Jobs\SyncMiniAppMenuButton;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\MiniAppMenuSync;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The Telegram Mini App chat menu buttons (plan 20 M5 §7, MA-23, MA-28,
 * MA-31). Refreshes the stored bot username from getMe() first, then:
 *
 *  - --chat=<telegram id>: one registered conversation;
 *  - --all: the SyncMiniAppMenus fan-out (plan 20 M6, MA-36, MA-37): a new
 *    generation, then every registered, not blocked conversation in steps
 *    of SyncMiniAppMenus::CHUNK, spread out; each button is computed from
 *    MiniAppAudience when its job runs (a 429 releases it for retry_after,
 *    403 → blocked). With --reset the bot-wide default is set first;
 *    refused (FAILURE) while the Mini App is on;
 *  - --reset: Telegram's default button instead of the Mini App one (with
 *    --chat for that chat; alone, the bot-wide default, which is never a
 *    web_app button).
 */
#[Signature('telegram:mini-app-menu {--all : Every registered conversation} {--reset : Set Telegram\'s default menu button instead (with --all: only while the Mini App is off)} {--chat= : One conversation, by its Telegram id}')]
#[Description('Sync or reset the Telegram Mini App menu button for one chat or every registered chat')]
class TelegramMiniAppMenu extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Sets the bot chat menu button: the Mini App "Catalog" button for customers who may use it (registered, not banned or blocked, Mini App switch on), Telegram\'s default for everyone else.
Use --chat=<telegram id> for one conversation, --all for every registered one (one fan-out job on the default queue that queues the chats in steps of 100, about 5 per second, and stops when a newer Mini App toggle starts another), and --reset to put back the default button (alone: the bot-wide default only; with --all the bot-wide default first, then every chat; refused while the Mini App is on, because each button follows the switch).
Turning the Mini App or the bot switch on or off in admin starts the same fan-out automatically; run it by hand after changing APP_URL. Side effects: Bot API calls (setChatMenuButton, getMe) and the stored bot username. Manual only, not scheduled.';

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
        // Each per-user button is computed when its job runs (MA-37), so a reset of every
        // chat is only possible while the Mini App is off.
        if ($reset && MiniAppSwitch::isEnabled()) {
            $this->error('The Mini App is on, so --all --reset would leave its button with every customer who may use it. Turn it off first with "telegram:mini-app disable" (that already resets every chat).');

            return self::FAILURE;
        }

        if ($reset && ! $this->resetDefault($client)) {
            return self::FAILURE;
        }

        $generation = MiniAppMenuSync::start();
        $this->info("Queued the menu button fan-out (generation {$generation}); it syncs every registered conversation in steps.");

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
