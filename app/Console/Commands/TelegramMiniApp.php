<?php

namespace App\Console\Commands;

use App\Actions\Telegram\SetMiniAppEnabled;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Emergency switch for the Telegram Mini App (plan 20 M6, MA-34). Only
 * "disable" (no checklist, no Telegram call, audited without an actor) and
 * "status": enabling needs the checklist and a password on the admin page.
 */
#[Signature('telegram:mini-app {action : disable or status}')]
#[Description('Disable the Telegram Mini App (emergency) or show its state')]
class TelegramMiniApp extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Emergency kill switch for the Telegram Mini App (/tma): "disable" turns it off at once, "status" shows whether it is on (the Mini App switch and the bot switch).
Run it when the Mini App misbehaves; it works while the Telegram API is down. Enabling is only possible on the admin Mini App page (checklist and password).
Side effects: disable writes the telegram.mini_app_enabled setting and an audit row, and queues the menu button fan-out that puts back Telegram\'s default button. Manual only, not scheduled.';

    public function handle(SetMiniAppEnabled $setMiniAppEnabled): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'status') {
            $this->info(MiniAppSwitch::isEnabled() ? __('admin.telegram_mini_app.state_enabled') : __('admin.telegram_mini_app.state_disabled'));

            return self::SUCCESS;
        }

        if ($action !== 'disable') {
            $this->error('Unknown action. Use disable or status (enable only on the admin Mini App page).');

            return self::INVALID;
        }

        $changed = $setMiniAppEnabled->disable(null, null);

        $this->info($changed ? __('admin.telegram_mini_app.flash.disabled') : __('admin.telegram_mini_app.state_disabled'));

        return self::SUCCESS;
    }
}
