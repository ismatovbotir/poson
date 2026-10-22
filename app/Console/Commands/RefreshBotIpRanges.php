<?php

namespace App\Console\Commands;

use App\Actions\Analytics\RefreshBotIpRanges as RefreshBotIpRangesAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Weekly download of the published crawler IP ranges (routes/console.php).
 */
#[Signature('analytics:refresh-bot-ranges')]
#[Description('Download the published crawler IP ranges into storage/app/bot-ranges.json')]
class RefreshBotIpRanges extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RefreshBotIpRangesAction $refresh): int
    {
        $result = $refresh->handle();

        foreach ($result['updated'] as $bot => $count) {
            $this->components->twoColumnDetail($bot, "{$count} range(s)");
        }

        if ($result['failed'] !== []) {
            $this->components->warn('Kept the previous ranges for: '.implode(', ', $result['failed']));
        }

        return $result['updated'] === [] && $result['failed'] !== [] ? self::FAILURE : self::SUCCESS;
    }
}
