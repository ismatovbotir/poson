<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Daily removal of stale Livewire temporary uploads (routes/console.php).
 */
#[Signature('uploads:purge-tmp {--dry-run : Only count what would be deleted}')]
#[Description('Delete Livewire temporary upload files older than 24 hours')]
class PurgeLivewireTmp extends Command
{
    public const DIRECTORY = 'livewire-tmp';

    public const MAX_AGE_HOURS = 24;

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'Deletes files older than 24 hours from the livewire-tmp/ folder of the Livewire temporary-upload disk. Livewire cleans this folder only when someone uploads a new file, so abandoned uploads (images, product documents) pile up.
Only that one folder is touched, not its sub-folders, and symbolic links are skipped. Media and product-documents/ are never read. Safe to run by hand (--dry-run only counts).
Side effect: permanently deletes the old temporary files. Scheduled daily at 02:20 Asia/Tashkent.';

    public function handle(): int
    {
        $disk = Storage::disk((string) (config('livewire.temporary_file_upload.disk') ?: config('filesystems.default')));
        $cutoff = now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $deleted = 0;
        $kept = 0;

        try {
            $paths = $disk->files(self::DIRECTORY);
        } catch (Throwable $exception) {
            report($exception);
            $paths = [];
        }

        foreach ($paths as $path) {
            try {
                if (! str_starts_with($path, self::DIRECTORY.'/') || $this->isSymlink($disk, $path) || $disk->lastModified($path) > $cutoff) {
                    $kept++;

                    continue;
                }

                if (! $this->option('dry-run')) {
                    $disk->delete($path);
                }

                $deleted++;
            } catch (Throwable $exception) {
                report($exception);
                $kept++;
            }
        }

        Log::info('uploads:purge-tmp', ['deleted' => $deleted, 'kept' => $kept, 'dry_run' => (bool) $this->option('dry-run')]);
        $this->components->info(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').$deleted.' temporary file(s), kept '.$kept.'.');

        return self::SUCCESS;
    }

    private function isSymlink(Filesystem $disk, string $path): bool
    {
        try {
            return is_link($disk->path($path));
        } catch (RuntimeException) {
            return false; // not a local disk
        }
    }
}
