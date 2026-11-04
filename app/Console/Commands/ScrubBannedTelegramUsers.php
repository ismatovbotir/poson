<?php

namespace App\Console\Commands;

use App\Actions\Telegram\ScrubBannedTelegramUsers as ScrubAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily scrub of banned Telegram bot users (routes/console.php, plan 21 T21-34).
 */
#[Signature('telegram:scrub-banned')]
#[Description('Strip personal data and messages of banned bot users past retention, keeping the ban')]
class ScrubBannedTelegramUsers extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'For banned bot users past retention (30 days unregistered, 12 months registered, counted from the later of the last message and the ban) deletes their messages and activities and clears name, phone, company, INN, username and CRM links. The Telegram id and the ban stay, so the user remains blocked. Threads linked to a lead are left whole.
Runs by itself every night after the Telegram prune; there is normally no reason to run it by hand.
Side effect: permanently deletes messages. Makes no Telegram call. Scheduled nightly.';

    public function handle(ScrubAction $scrub): int
    {
        $this->components->info($scrub->handle().' banned user(s) scrubbed.');

        return self::SUCCESS;
    }
}
