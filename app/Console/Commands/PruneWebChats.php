<?php

namespace App\Console\Commands;

use App\Actions\Chat\Web\PruneWebChats as PruneAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly retention of website chats (routes/console.php, plan 28 C5d).
 */
#[Signature('chat:prune')]
#[Description('Close idle website chats and delete old ones per retention; purge banned chats')]
class PruneWebChats extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Website chat retention: closes open chats idle for 24 hours; deletes bot-only chats after 30 days, handed-off chats without a lead after 90 days and lead-linked chats after 12 months (messages and AI turns go with them); clears the IP hash after 30 days; for banned chats deletes the messages, name, phone and page after 30 days and the row after 12 months. The ban keeps working until the row is deleted.
Runs by itself every night at 03:10; there is normally no reason to run it by hand.
Side effect: permanently deletes chats and messages. Makes no network call. Scheduled nightly.';

    public function handle(PruneAction $prune): int
    {
        $counts = $prune->handle();

        $this->components->info(sprintf('%d expired, %d deleted, %d IP hashes cleared, %d banned purged.', $counts['expired'], $counts['deleted'], $counts['ip_cleared'], $counts['purged']));

        return self::SUCCESS;
    }
}
