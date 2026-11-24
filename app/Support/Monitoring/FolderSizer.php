<?php

namespace App\Support\Monitoring;

use App\Enums\DiskMeasureStatus;
use App\Enums\DiskTarget;
use Closure;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Measures the project folder (plan 37). Stat only: it never opens, reads,
 * hashes or touches a file, never follows a symlink, and never lets a name
 * or a path leave this class (only DiskTarget rows with numbers).
 *
 * Roots (DM-1): R1 = realpath(base_path()); R2 = realpath(storage_path())
 * only when storage/ is a link to a place outside R1 that is not an ancestor
 * of R1. Nothing else is ever walked.
 *
 * One pass over R1, one iterator per top-level child in a fixed order:
 * storage (or R2), public, every other child (sorted), vendor, node_modules,
 * .git. A file belongs to the bucket named by its first path segment
 * (exact match), else to "other"; storage files also to their detail row
 * (exact folder prefix). The total is the sum of the buckets. Budgets
 * (DM-5) use hrtime in the loop and never set_time_limit(); hitting one
 * marks the unfinished buckets partial.
 */
class FolderSizer
{
    public const MAX_SECONDS = 150;

    public const MAX_ENTRIES = 1_500_000;

    /**
     * Progress callback cadence, in entries.
     */
    private const PROGRESS_EVERY = 512;

    private string $base;

    private string $storage;

    private int $entries = 0;

    private int $startedAt = 0;

    private bool $budgetHit = false;

    private bool $aborted = false;

    private int $files = 0;

    private int $bytes = 0;

    /**
     * @var array<string, array{bytes: int, files: int, partial: bool, ns: int}>
     */
    private array $figures = [];

    /**
     * @var array<string, bool>
     */
    private array $detailPartial = [];

    private ?Closure $progress = null;

    private ?DiskTarget $current = null;

    private string $storageRoot = '';

    public function __construct(?string $base = null, ?string $storage = null, private ?int $maxEntries = null, private ?int $maxSeconds = null)
    {
        $this->base = $base ?? base_path();
        $this->storage = $storage ?? storage_path();
        $this->maxEntries ??= self::MAX_ENTRIES;
        $this->maxSeconds ??= self::MAX_SECONDS;
    }

    /**
     * @param  (Closure(DiskTarget, int, int): bool)|null  $progress  bucket, files, bytes so far; false aborts the run
     */
    public function measure(?Closure $progress = null): DiskMeasurement
    {
        $this->entries = 0;
        $this->budgetHit = false;
        $this->aborted = false;
        $this->progress = $progress;
        $this->startedAt = hrtime(true);
        $this->figures = [];
        $this->detailPartial = [];

        foreach ([...DiskTarget::buckets(), ...DiskTarget::storageDetails()] as $target) {
            $this->figures[$target->value] = ['bytes' => 0, 'files' => 0, 'partial' => false, 'ns' => 0];
        }

        $root = realpath($this->base);

        if ($root === false || ! is_dir($root)) {
            return $this->unreadableResult();
        }

        [$storageRoot, $storageStatus] = $this->resolveStorage($root);
        $this->storageRoot = $storageRoot ?? '';
        $names = $this->children($root);
        $statuses = [];

        if ($names === null) {
            return $this->unreadableResult();
        }

        // 1. storage (R1/storage or R2)
        if ($storageRoot !== null) {
            $statuses[DiskTarget::Storage->value] = $this->walkBucket(DiskTarget::Storage, [$storageRoot]);
        } else {
            $statuses[DiskTarget::Storage->value] = $storageStatus;
        }

        // 2. public, 3. other children, 4. vendor, node_modules, .git
        $named = [];
        $others = [];

        foreach ($names as $name) {
            $bucket = $this->bucketOf($name);

            if ($bucket === DiskTarget::Storage && $storageRoot !== null) {
                // Covered above (R1/storage itself, or a link replaced by R2).
                continue;
            }

            if ($bucket === DiskTarget::Other) {
                $others[] = $root.DIRECTORY_SEPARATOR.$name;
            } else {
                $named[$bucket->value][] = $root.DIRECTORY_SEPARATOR.$name;
            }
        }

        $statuses[DiskTarget::Public->value] = $this->walkBucket(DiskTarget::Public, $named[DiskTarget::Public->value] ?? []);
        $statuses[DiskTarget::Other->value] = $this->walkBucket(DiskTarget::Other, $others);

        foreach ([DiskTarget::Vendor, DiskTarget::NodeModules, DiskTarget::Git] as $bucket) {
            $statuses[$bucket->value] = $this->walkBucket($bucket, $named[$bucket->value] ?? []);
        }

        // A bucket that was never reached (budget or abort) is partial, not "missing".
        return $this->result($statuses);
    }

    /**
     * R1/storage, or R2, or null (with the status to show).
     *
     * @return array{0: ?string, 1: DiskMeasureStatus}
     */
    private function resolveStorage(string $root): array
    {
        $separator = DIRECTORY_SEPARATOR;
        $real = realpath($this->storage);
        $standard = $root.$separator.'storage';

        if ($real === false) {
            return [null, file_exists($this->storage) || is_link($this->storage) ? DiskMeasureStatus::Unreadable : DiskMeasureStatus::Missing];
        }

        if ($real === $standard) {
            return is_dir($real) ? [$real, DiskMeasureStatus::Ok] : [null, DiskMeasureStatus::Unreadable];
        }

        $inside = $real === $root || str_starts_with($real, $root.$separator);
        $ancestor = str_starts_with($root, rtrim($real, $separator).$separator);

        if ($inside || $ancestor || ! is_dir($real)) {
            return [null, DiskMeasureStatus::Unreadable];
        }

        return [$real, DiskMeasureStatus::Ok];
    }

    private function bucketOf(string $name): DiskTarget
    {
        foreach ([DiskTarget::Storage, DiskTarget::Public, DiskTarget::Vendor, DiskTarget::NodeModules, DiskTarget::Git] as $bucket) {
            if ($name === $bucket->folderName()) {
                return $bucket;
            }
        }

        return DiskTarget::Other;
    }

    /**
     * Sorted entry names of a directory, or null when it cannot be listed.
     *
     * @return list<string>|null
     */
    private function children(string $directory): ?array
    {
        $names = @scandir($directory);

        if ($names === false) {
            return null;
        }

        $names = array_values(array_diff($names, ['.', '..']));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Walk the given top-level entries into one bucket.
     *
     * @param  list<string>  $paths
     */
    private function walkBucket(DiskTarget $bucket, array $paths): DiskMeasureStatus
    {
        $figures = &$this->figures[$bucket->value];

        if ($paths === []) {
            return DiskMeasureStatus::Missing;
        }

        if ($this->budgetHit || $this->aborted) {
            $figures['partial'] = true;

            return DiskMeasureStatus::Partial;
        }

        $this->current = $bucket;
        $started = hrtime(true);
        $this->files = 0;
        $this->bytes = 0;
        $partial = false;

        $this->report();

        foreach ($paths as $path) {
            try {
                if (is_link($path)) {
                    continue;
                }

                if (is_dir($path)) {
                    $partial = $this->walkTree($bucket, $path) || $partial;
                } elseif (is_file($path)) {
                    $this->addFile($bucket, new SplFileInfo($path), null);
                }
            } catch (BudgetExceeded) {
                $partial = true;

                break;
            } catch (Throwable) {
                $partial = true;
            }
        }

        $figures['bytes'] += $this->bytes;
        $figures['files'] += $this->files;
        $figures['partial'] = $figures['partial'] || $partial;
        $figures['ns'] += hrtime(true) - $started;
        $this->report();

        return $figures['partial'] ? DiskMeasureStatus::Partial : DiskMeasureStatus::Ok;
    }

    /**
     * Walk one directory tree; true when something was left out (unreadable or cut short).
     */
    private function walkTree(DiskTarget $bucket, string $directory): bool
    {
        $partial = false;
        $isStorage = $bucket === DiskTarget::Storage;
        $prefixLength = strlen($directory) + 1;

        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            function (SplFileInfo $current, mixed $key, RecursiveIterator $iterator) use (&$partial, $isStorage, $prefixLength): bool {
                $this->tick();

                if ($current->isLink()) {
                    return false;
                }

                if ($iterator->hasChildren() && ! $current->isReadable()) {
                    $partial = true;

                    if ($isStorage) {
                        $this->markDetailPartial(substr($current->getPathname(), $prefixLength));
                    }

                    return false;
                }

                return true;
            },
        );

        try {
            foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD) as $info) {
                // PHP swallows exceptions thrown while the iterator advances (CATCH_GET_CHILD): stop here as well.
                if ($this->budgetHit || $this->aborted) {
                    throw new BudgetExceeded;
                }

                $this->addFile($bucket, $info, $isStorage ? substr($info->getPathname(), $prefixLength) : null);
            }
        } catch (BudgetExceeded $exception) {
            throw $exception;
        } catch (Throwable) {
            $partial = true;
        }

        return $partial;
    }

    /**
     * Count one regular file (stat only). $relative is the path inside storage/ for detail rows.
     */
    private function addFile(DiskTarget $bucket, SplFileInfo $info, ?string $relative): void
    {
        try {
            if ($info->isLink() || ! $info->isFile()) {
                return;
            }

            $size = $info->getSize();
        } catch (RuntimeException) {
            return;
        }

        $this->files++;
        $this->bytes += $size;

        if ($bucket === DiskTarget::Storage && $relative !== null && $relative !== '') {
            $detail = $this->detailOf($relative);

            if ($detail !== null) {
                $this->figures[$detail->value]['bytes'] += $size;
                $this->figures[$detail->value]['files']++;
            }
        }
    }

    /**
     * The detail row of a path inside storage/ (exact folder + separator, so storage/logs-old is not logs).
     */
    private function detailOf(string $relative): ?DiskTarget
    {
        $relative = DIRECTORY_SEPARATOR === '/' ? $relative : str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        foreach (DiskTarget::storageDetails() as $detail) {
            if (str_starts_with($relative, $detail->storageFolder().'/')) {
                return $detail;
            }
        }

        return null;
    }

    private function markDetailPartial(string $relative): void
    {
        $detail = $this->detailOf($relative.'/');

        if ($detail !== null) {
            $this->figures[$detail->value]['partial'] = true;
        }
    }

    /**
     * Count one entry and enforce the budgets (DM-5).
     */
    private function tick(): void
    {
        $this->entries++;

        if ($this->entries > $this->maxEntries) {
            $this->budgetHit = true;

            throw new BudgetExceeded;
        }

        if ($this->entries % self::PROGRESS_EVERY === 0) {
            if (hrtime(true) - $this->startedAt > $this->maxSeconds * 1_000_000_000) {
                $this->budgetHit = true;

                throw new BudgetExceeded;
            }

            $this->report();

            if ($this->aborted) {
                throw new BudgetExceeded;
            }
        }
    }

    /**
     * Tell the caller where we are; a false answer stops the run (it was superseded).
     */
    private function report(): void
    {
        if ($this->progress === null || $this->current === null) {
            return;
        }

        if (($this->progress)($this->current, $this->files, $this->bytes) === false) {
            $this->aborted = true;
        }
    }

    /**
     * @param  array<string, DiskMeasureStatus>  $statuses
     */
    private function result(array $statuses): DiskMeasurement
    {
        $rows = [];
        $bytes = 0;
        $files = 0;
        $ns = 0;
        $totalPartial = false;

        foreach (DiskTarget::buckets() as $bucket) {
            $figures = $this->figures[$bucket->value];
            $status = $statuses[$bucket->value] ?? DiskMeasureStatus::Missing;

            if ($status === DiskMeasureStatus::Ok && $figures['partial']) {
                $status = DiskMeasureStatus::Partial;
            }

            $rows[$bucket->value] = ['bytes' => $figures['bytes'], 'files' => $figures['files'], 'status' => $status, 'ms' => intdiv($figures['ns'], 1_000_000)];
            $bytes += $figures['bytes'];
            $files += $figures['files'];
            $ns += $figures['ns'];
            $totalPartial = $totalPartial || in_array($status, [DiskMeasureStatus::Partial, DiskMeasureStatus::Unreadable], true);
        }

        foreach (DiskTarget::storageDetails() as $detail) {
            $figures = $this->figures[$detail->value];
            $storage = $rows[DiskTarget::Storage->value]['status'];

            if (in_array($storage, [DiskMeasureStatus::Unreadable, DiskMeasureStatus::Missing], true)) {
                $status = $storage;
            } elseif ($figures['partial'] || $storage === DiskMeasureStatus::Partial && $this->budgetHit) {
                $status = DiskMeasureStatus::Partial;
            } else {
                $status = $figures['files'] === 0 && ! $this->detailExists($detail) ? DiskMeasureStatus::Missing : DiskMeasureStatus::Ok;
            }

            $rows[$detail->value] = ['bytes' => $figures['bytes'], 'files' => $figures['files'], 'status' => $status, 'ms' => 0];
        }

        $rows[DiskTarget::ProjectTotal->value] = [
            'bytes' => $bytes,
            'files' => $files,
            'status' => $totalPartial ? DiskMeasureStatus::Partial : DiskMeasureStatus::Ok,
            'ms' => intdiv($ns, 1_000_000),
        ];

        return new DiskMeasurement($rows, $this->budgetHit, $this->aborted);
    }

    private function detailExists(DiskTarget $detail): bool
    {
        return $this->storageRoot !== '' && is_dir($this->storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $detail->storageFolder()));
    }

    private function unreadableResult(): DiskMeasurement
    {
        $rows = [];

        foreach ([DiskTarget::ProjectTotal, ...DiskTarget::buckets(), ...DiskTarget::storageDetails()] as $target) {
            $rows[$target->value] = ['bytes' => 0, 'files' => 0, 'status' => DiskMeasureStatus::Unreadable, 'ms' => 0];
        }

        return new DiskMeasurement($rows);
    }
}
