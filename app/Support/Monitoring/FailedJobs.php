<?php

namespace App\Support\Monitoring;

use App\Actions\Monitoring\MonitoringActionException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Read side of the failed jobs list (plan 17 §5, MON-S3, S4, S8). Only uuid,
 * queue, failed_at, the displayName extracted by the database and a
 * 2000-character slice of the exception are ever selected. The payload is
 * never selected whole and never unserialized.
 */
final class FailedJobs
{
    public const PER_PAGE = 25;

    public const MAX_BULK = 50;

    public const EXCERPT = 2000;

    public const ONE_LINE = 300;

    public const MESSAGE = 1000;

    /**
     * Failed jobs older than this many hours get a warning before a retry.
     */
    public const STALE_HOURS = 24;

    /**
     * @return LengthAwarePaginator<int, object{uuid: string, queue: string, failed_at: string, display_name: string|null, exception_excerpt: string|null}>
     */
    public static function page(): LengthAwarePaginator
    {
        return self::select()->orderByDesc('id')->paginate(self::PER_PAGE, pageName: 'failed');
    }

    /**
     * @return object{uuid: string, queue: string, failed_at: string, display_name: string|null, exception_excerpt: string|null}|null
     */
    public static function find(string $uuid): ?object
    {
        return Str::isUuid($uuid) ? self::select()->where('uuid', $uuid)->first() : null;
    }

    /**
     * uuid => normalized class, for existing uuids only.
     *
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    public static function classes(array $uuids): array
    {
        $classes = [];

        foreach (DB::table('failed_jobs')->whereIn('uuid', $uuids)->selectRaw('uuid, '.self::displayNameSql().' as display_name')->get() as $row) {
            $classes[$row->uuid] = self::normalizeClass($row->display_name);
        }

        return $classes;
    }

    /**
     * Failed jobs of the given classes (a fixed list, never user input) from
     * the last $days days, by failed_at: their number and the newest
     * failure's time. One aggregate query; the database extracts displayName
     * from the payload inside the WHERE clause, and the payload itself is
     * never selected.
     *
     * @param  list<class-string>  $classes
     * @return array{count: int, newest: Carbon|null}
     */
    public static function recentForClasses(array $classes, int $days = 7): array
    {
        if ($classes === []) {
            return ['count' => 0, 'newest' => null];
        }

        $row = self::forClasses($classes)->where('failed_at', '>=', now()->subDays($days))
            ->selectRaw('count(*) as total, max(failed_at) as newest')
            ->first();

        return ['count' => (int) ($row->total ?? 0), 'newest' => ($row->newest ?? null) === null ? null : Carbon::parse($row->newest)];
    }

    /**
     * Number of failed jobs of the given classes, all time (see recentForClasses()).
     *
     * @param  list<class-string>  $classes
     */
    public static function countForClasses(array $classes): int
    {
        return $classes === [] ? 0 : self::forClasses($classes)->count();
    }

    /**
     * @param  list<class-string>  $classes
     */
    private static function forClasses(array $classes): Builder
    {
        $name = self::displayNameSql();

        return DB::table('failed_jobs')->where(function (Builder $query) use ($classes, $name): void {
            foreach ($classes as $class) {
                $query->orWhereRaw("{$name} = ?", [$class])
                    ->orWhereRaw("{$name} like ? escape '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $class).'@%']);
            }
        });
    }

    /**
     * Only real uuids, no duplicates, 1..MAX_BULK of them. Never "all" or a range.
     *
     * @param  array<mixed>  $ids
     * @return list<string>
     *
     * @throws MonitoringActionException invalid | too_many
     */
    public static function parseIds(array $ids): array
    {
        // Nested arrays and other non-strings are refused before array_unique() touches them.
        $strings = array_values(array_filter($ids, is_string(...)));
        $ids = count($strings) === count($ids) ? array_values(array_unique($strings)) : [];

        if ($ids === []) {
            throw new MonitoringActionException('invalid');
        }

        if (count($ids) > self::MAX_BULK) {
            throw new MonitoringActionException('too_many');
        }

        foreach ($ids as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new MonitoringActionException('invalid');
            }
        }

        return array_map(strtolower(...), $ids);
    }

    public static function normalizeClass(?string $displayName): string
    {
        return Str::before((string) $displayName, '@');
    }

    public static function isRetryable(string $class): bool
    {
        return in_array(self::normalizeClass($class), (array) config('monitoring.retry_classes', []), true);
    }

    /**
     * Translation key suffix of the human label of a retryable class (null otherwise).
     */
    public static function labelKey(string $class): ?string
    {
        return self::isRetryable($class) ? Str::snake(class_basename(self::normalizeClass($class))) : null;
    }

    public static function oneLine(?string $exception): string
    {
        return Redactor::line($exception, self::ONE_LINE);
    }

    /**
     * Exception class, redacted message (≤ 1000) and the first app/ frame, from
     * the 2000-character slice.
     *
     * @return array{class: string, message: string, frame: string|null}
     */
    public static function detail(?string $excerpt): array
    {
        $excerpt = (string) $excerpt;
        $head = trim(Str::before($excerpt, "\nStack trace:"));
        $class = preg_match('/^([A-Za-z_][\w\\\\]*)(?::|\s|$)/', $head, $match) === 1 ? $match[1] : '';
        $message = $class === '' ? $head : ltrim(substr($head, strlen($class)), ': ');
        $redacted = Redactor::clean($excerpt, self::EXCERPT, cutTrace: false);
        $frame = preg_match('/#\d+ (~\/app\/[^\s(]+(?:\(\d+\))?)/', $redacted, $found) === 1 ? $found[1] : null;

        return [
            'class' => Redactor::clean($class, 200),
            'message' => Redactor::clean($message, self::MESSAGE),
            'frame' => $frame,
        ];
    }

    public static function displayNameSql(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "json_extract(payload, '$.displayName')"
            : "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName'))";
    }

    private static function select(): Builder
    {
        $slice = DB::getDriverName() === 'sqlite' ? 'substr(exception, 1, '.self::EXCERPT.')' : 'LEFT(exception, '.self::EXCERPT.')';

        return DB::table('failed_jobs')
            ->select('uuid', 'queue', 'failed_at')
            ->selectRaw(self::displayNameSql().' as display_name')
            ->selectRaw($slice.' as exception_excerpt');
    }
}
