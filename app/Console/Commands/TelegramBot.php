<?php

namespace App\Console\Commands;

use App\Actions\Telegram\SetBotEnabled;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Emergency switch for the customer bot (plan 14 v2, TG-37). Only
 * "disable" (no checklist, audited without an actor) and "status":
 * enabling needs the checklist and a password on the admin bot page.
 */
#[Signature('telegram:bot {action : disable or status}')]
#[Description('Disable the Telegram customer bot (emergency) or show its state')]
class TelegramBot extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Emergency kill switch for the Telegram customer bot: "disable" turns customer processing off at once (staff alerts keep working), "status" shows whether it is on.
Run it when the bot misbehaves or the token may have leaked. Enabling is only possible on the admin bot page (checklist and password).
Side effects: disable writes the telegram.bot_enabled setting and an audit row. Manual only, not scheduled.';

    public function handle(SetBotEnabled $setBotEnabled): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'status') {
            $this->info(BotSwitch::isEnabled() ? __('admin.telegram_bot.state_enabled') : __('admin.telegram_bot.state_disabled'));

            return self::SUCCESS;
        }

        if ($action !== 'disable') {
            $this->error('Unknown action. Use disable or status (enable only on the admin bot page).');

            return self::INVALID;
        }

        $changed = $setBotEnabled->disable(null, null);

        $this->info($changed ? __('admin.telegram_bot.flash.disabled') : __('admin.telegram_bot.state_disabled'));

        return self::SUCCESS;
    }
}
