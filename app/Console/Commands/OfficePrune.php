<?php

namespace App\Console\Commands;

use App\Actions\Office\PruneBusinessData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Plan 41 §9: nightly Virtual office retention (routes/console.php, 03:30 Tashkent).
 */
#[Signature('office:prune')]
#[Description('Delete old Virtual office (Telegram Business) chats, messages and analyses by the retention rules')]
class OfficePrune extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Virtual office retention (plan 41): deletes unmarked chat stubs 14 days after the last client message, blanks the names of ignored chats after 14 days, deletes messages and analyses of client chats silent for 12 months (the chat row stays while it is linked to a lead), keeps only the newest 5 analyses per chat, blanks draft replies older than 30 days and deletes connections of non-allowed accounts inactive for 30 days.
Telegram groups (plan 44): deletes the messages, threads and analyses of groups silent for 12 months, members who left and were last seen over 12 months ago, untracked group stubs silent for 30 days, blanks analysis and thread drafts older than 30 days and the titles of ignored groups.
Works in chunks; logs counts only. Leads and customers are never deleted.
Side effect: permanently deletes Telegram Business data. Scheduled nightly at 03:30 (Tashkent).';

    public function handle(PruneBusinessData $prune): int
    {
        $counts = $prune->handle();

        Log::info('office.prune', $counts);

        foreach ($counts as $name => $count) {
            $this->components->twoColumnDetail($name, (string) $count);
        }

        return self::SUCCESS;
    }
}
