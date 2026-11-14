<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ForceDeleteProduct;
use App\Enums\MonitoringAction as MonitoringActionType;
use App\Models\MonitoringAction;
use App\Models\Product;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nightly permanent deletion of products that stayed in the trash too long (routes/console.php).
 */
#[Signature('catalog:purge-trash {--dry-run : Only count what would be deleted} {--force : Run without the production confirmation}')]
#[Description('Permanently delete products that have been in the trash for more than 30 days')]
class PurgeProductTrash extends Command
{
    use ConfirmableTrait;

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Permanently deletes products that were moved to the trash more than 30 days ago (config catalog.trash_days), with their images and documents. Order, quote and lead lines keep their name and SKU snapshot; only the link to the product is cleared.
Every product is re-checked under a row lock right before deletion, so a product restored meanwhile is kept. A failure on one product is reported and the rest continue. --dry-run only counts. In production it asks for confirmation unless --force is given.
Side effect: irreversible deletion; one audit row (count only) per run that deleted something. Scheduled nightly at 02:15 Asia/Tashkent with --force.';

    public function handle(ForceDeleteProduct $forceDelete): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $cutoff = now()->subDays((int) config('catalog.trash_days', 30));
        $deleted = 0;
        $failed = 0;

        Product::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->chunkById(100, function ($products) use ($dryRun, $cutoff, $forceDelete, &$deleted, &$failed): void {
                foreach ($products as $candidate) {
                    if ($dryRun) {
                        $deleted++;

                        continue;
                    }

                    try {
                        $deleted += DB::transaction(function () use ($candidate, $cutoff, $forceDelete): int {
                            $product = Product::onlyTrashed()->whereKey($candidate->id)->lockForUpdate()->first();

                            if ($product === null || $product->deleted_at === null || $product->deleted_at->gt($cutoff)) {
                                return 0;
                            }

                            $forceDelete->handle($product);

                            return 1;
                        });
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                    }
                }
            });

        Log::info('catalog:purge-trash', ['deleted' => $deleted, 'failed' => $failed, 'dry_run' => $dryRun]);

        if (! $dryRun && $deleted > 0) {
            MonitoringAction::query()->create([
                'user_id' => null,
                'action' => MonitoringActionType::TrashPurge,
                'target' => 'catalog:purge-trash',
                'count' => $deleted,
            ]);
        }

        $this->components->info(($dryRun ? 'Would delete ' : 'Deleted ').$deleted.' product(s) from the trash, '.$failed.' failed.');

        return self::SUCCESS;
    }
}
