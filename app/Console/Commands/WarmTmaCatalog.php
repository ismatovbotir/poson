<?php

namespace App\Console\Commands;

use App\Actions\Telegram\MiniApp\WarmTmaCatalog as WarmTmaCatalogAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Plan 45 B: fills the Mini App filter facet cache (routes/console.php, every 6 hours).
 */
#[Signature('tma:warm-catalog')]
#[Description('Fill the Mini App filter cache (unfiltered facets of every visible category in uz, ru and en)')]
class WarmTmaCatalog extends Command
{
    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Builds the unfiltered filter facets (brands, stock, price range, attribute filters with their counts) of every visible category in every site locale and stores them in the cache for 6 hours, so the Mini App filter screen opens without the facet queries. Hidden categories are skipped; filtered filter screens are always built live. Writes the time, the number of entries and the duration shown on Admin → Telegram → Mini App.
Safe to run at any time: it only overwrites cache entries and never touches products, carts or orders. If the admin "Rebuild catalog cache" button is running at the same moment, this run skips. Scheduled every 6 hours.';

    public function handle(WarmTmaCatalogAction $warm): int
    {
        $result = $warm->handle();

        if ($result === null) {
            $this->components->warn('Another catalog warm is running, skipped.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('entries', (string) $result['entries']);
        $this->components->twoColumnDetail('ms', (string) $result['ms']);

        return self::SUCCESS;
    }
}
