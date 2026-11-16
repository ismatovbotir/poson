<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daily removal of stale media-library temporary directories (routes/console.php).
 */
#[Signature('media:clean-temp {--dry-run : Only count what would be deleted}')]
#[Description('Delete media-library temporary directories older than 24 hours')]
class CleanMediaTemp extends Command
{
    public const MAX_AGE_HOURS = 24;

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Deletes direct sub-folders older than 24 hours from the media-library temporary folder (config media-library.temporary_directory_path, default storage/media-library/temp). Media Library leaves these folders behind when an image conversion or a download is interrupted.
Only direct children are touched: symbolic links are never followed or removed, loose files are left alone, and nothing outside that folder is read. Prints and logs counts only (deleted, skipped, failed), never paths. Safe to run by hand (--dry-run only counts).
Side effect: permanently deletes the stale temporary folders. Scheduled daily at 02:25 Asia/Tashkent.';

    public function handle(): int
    {
        $configured = config('media-library.temporary_directory_path');
        $base = realpath((string) (filled($configured) ? $configured : storage_path('media-library/temp')));
        $dryRun = (bool) $this->option('dry-run');
        $deleted = 0;
        $skipped = 0;
        $failed = 0;

        if ($base !== false && is_dir($base)) {
            $cutoff = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();

            foreach (scandir($base) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $child = $base.DIRECTORY_SEPARATOR.$name;

                try {
                    $real = is_link($child) ? false : realpath($child);

                    if ($real === false || ! is_dir($real) || dirname($real) !== $base || filemtime($real) >= $cutoff) {
                        $skipped++;

                        continue;
                    }

                    if ($dryRun || File::deleteDirectory($real)) {
                        $deleted++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                }
            }
        }

        Log::info('media:clean-temp', ['deleted' => $deleted, 'skipped' => $skipped, 'failed' => $failed, 'dry_run' => $dryRun]);
        $this->components->info(($dryRun ? 'Would delete' : 'Deleted')." {$deleted} temporary folder(s), skipped {$skipped}, failed {$failed}.");

        return self::SUCCESS;
    }
}
