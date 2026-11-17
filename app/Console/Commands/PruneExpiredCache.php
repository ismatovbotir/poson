<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily removal of expired rows of the database cache store (routes/console.php, plan 34 HC-11).
 */
#[Signature('cache:prune-expired')]
#[Description('Delete expired rows from the database cache and cache lock tables')]
class PruneExpiredCache extends Command
{
    public const CHUNK = 1000;

    /**
     * The console command help text.
     *
     * @var string
     */
    protected $help = 'The database cache store never deletes rows that have expired (they are only ignored on read), so versioned keys such as the home page cache pile up. This deletes the rows of the cache table (and the lock table) whose expiration time has passed.
Only expired rows are removed: live cache entries, sessions and the queue are untouched. Does nothing when the default cache store is not "database". Safe to run by hand. Scheduled daily at 04:05 Asia/Tashkent.';

    public function handle(): int
    {
        $store = config('cache.stores.database');

        if (config('cache.default') !== 'database' || ! is_array($store)) {
            $this->info('The cache store is not "database": nothing to prune.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ([$store['table'] ?? 'cache', $store['lock_table'] ?? 'cache_locks'] as $table) {
            $query = DB::connection($store['connection'] ?? null)->table($table);

            do {
                $keys = (clone $query)->where('expiration', '<=', now()->getTimestamp())->limit(self::CHUNK)->pluck('key')->all();
                $deleted += $keys === [] ? 0 : (clone $query)->whereIn('key', $keys)->where('expiration', '<=', now()->getTimestamp())->delete();
            } while (count($keys) === self::CHUNK);
        }

        $this->info("Deleted {$deleted} expired cache rows.");

        return self::SUCCESS;
    }
}
