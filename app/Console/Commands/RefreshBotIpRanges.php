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
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Downloads the published IP ranges of known crawlers (search engines and AI bots) into storage/app/bot-ranges.json. They are used to verify crawler hits in the traffic analytics.
Keeps the previous ranges for any source that fails. Run it once on deploy so verification has ranges before the first night.
Side effect: outgoing HTTP requests and a file write. Scheduled weekly (Monday).';

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
