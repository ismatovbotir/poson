<?php

namespace App\Support\Careers;

use App\Models\Vacancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the site has a public vacancy (plan 31), cached: the main nav and
 * footer link /careers only while at least one vacancy is published and not
 * past valid_through. The cache keeps the earliest expiry among the published
 * vacancies, so a vacancy that expires is noticed without a flush (the date
 * is compared with now() on every read). Vacancy saves and deletes flush it.
 *
 * @phpstan-type Snapshot array{open: int, expires: list<string>}
 */
class VacancyDirectory
{
    public const CACHE_KEY = 'site.careers.v1';

    private const MEMO = 'site.careers.memo';

    public static function hasPublished(): bool
    {
        $snapshot = static::snapshot();

        if ($snapshot['open'] > 0) {
            return true;
        }

        $today = Carbon::now(Vacancy::TIMEZONE)->toDateString();

        foreach ($snapshot['expires'] as $date) {
            if ($date >= $today) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{open: int, expires: list<string>}
     */
    protected static function snapshot(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }

        $snapshot = Cache::rememberForever(self::CACHE_KEY, function (): array {
            $rows = Vacancy::query()->where('is_published', true)->toBase()->get(['valid_through']);

            return [
                'open' => $rows->whereNull('valid_through')->count(),
                'expires' => $rows->whereNotNull('valid_through')->map(fn ($row): string => substr((string) $row->valid_through, 0, 10))->unique()->values()->all(),
            ];
        });

        app()->instance(self::MEMO, $snapshot);

        return $snapshot;
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
        Cache::forget(self::CACHE_KEY);
    }
}
