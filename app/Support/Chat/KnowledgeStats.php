<?php

namespace App\Support\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Small cache-backed state of the website knowledge index (plan 33):
 *  - the content version (K-9): a counter bumped after commit on every
 *    write or delete of chunks, part of the AI knowledge cache key, so
 *    delete+insert and untouched timestamps still change the key;
 *  - the skip counters: for every source the reasons its chunks were not
 *    indexed (brand, money, injection, ...), counts only, never text.
 */
final class KnowledgeStats
{
    public const VERSION_KEY = 'chatbot:knowledge:version';

    public const SKIPS_KEY = 'chatbot:knowledge:skips';

    private const SKIPS_CAP = 5000;

    /**
     * Invalidate the AI knowledge block once the current transaction commits
     * (immediately when there is none).
     */
    public static function bump(): void
    {
        DB::afterCommit(function (): void {
            try {
                Cache::add(self::VERSION_KEY, 1);
                Cache::increment(self::VERSION_KEY);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    public static function version(): int
    {
        $version = Cache::get(self::VERSION_KEY);

        return is_numeric($version) ? max(1, (int) $version) : 1;
    }

    /**
     * Remember why chunks of a source were skipped on its last run (an empty
     * list forgets the source).
     *
     * @param  list<string>  $reasons  One reason per skipped chunk
     */
    public static function recordSkips(string $source, array $reasons): void
    {
        try {
            $lock = Cache::lock(self::SKIPS_KEY.':lock', 5);
            $lock->block(2);

            try {
                $skips = self::skips();

                if ($reasons === []) {
                    unset($skips[$source]);
                } elseif (isset($skips[$source]) || count($skips) < self::SKIPS_CAP) {
                    $skips[$source] = array_count_values($reasons);
                }

                Cache::forever(self::SKIPS_KEY, $skips);
            } finally {
                $lock->release();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Skipped chunks per reason over all sources.
     *
     * @return array<string, int>
     */
    public static function skipCounts(): array
    {
        $counts = [];

        foreach (self::skips() as $reasons) {
            foreach ($reasons as $reason => $count) {
                $counts[$reason] = ($counts[$reason] ?? 0) + $count;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function skips(): array
    {
        $skips = Cache::get(self::SKIPS_KEY);

        return is_array($skips) ? $skips : [];
    }
}
