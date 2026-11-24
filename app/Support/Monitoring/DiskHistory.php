<?php

namespace App\Support\Monitoring;

use App\Enums\DiskMeasureStatus;
use App\Enums\DiskTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns stored snapshot rows into what the Project size section shows
 * (plan 37): per target the latest figures, the change over 1 / 7 / 30
 * days and a series for the sparkline. Rows with an unknown target or
 * status are ignored (DM-24). Pure: no query, no filesystem.
 */
final class DiskHistory
{
    /**
     * Days back that the changes compare against.
     */
    public const CHANGE_DAYS = [1, 7, 30];

    /**
     * @param  Collection<int, object{target: string, taken_on: string, size_bytes: int|string|null, file_count: int|string|null, status: string, measured_at: string|null}>  $rows  ordered by taken_on
     * @return array<string, array{bytes: int, files: int, status: DiskMeasureStatus, date: string, measured_at: ?Carbon, changes: array<int, ?int>, bases: array<int, ?string>, series: list<int>}>
     */
    public static function build(Collection $rows, int $days): array
    {
        $perTarget = [];

        foreach ($rows as $row) {
            $target = DiskTarget::tryFrom((string) $row->target);
            $status = DiskMeasureStatus::tryFrom((string) $row->status);

            if ($target === null || $status === null || $row->size_bytes === null) {
                continue;
            }

            $perTarget[$target->value][substr((string) $row->taken_on, 0, 10)] = [
                'bytes' => (int) $row->size_bytes,
                'files' => (int) $row->file_count,
                'status' => $status,
                'measured_at' => $row->measured_at === null ? null : Carbon::parse($row->measured_at),
            ];
        }

        $result = [];

        foreach ($perTarget as $target => $byDate) {
            ksort($byDate);
            $date = array_key_last($byDate);
            $latest = $byDate[$date];

            $changes = [];
            $bases = [];

            foreach (self::CHANGE_DAYS as $back) {
                $limit = Carbon::parse($date)->subDays($back)->toDateString();
                $oldest = Carbon::parse($date)->subDays($back * 2)->toDateString();
                $base = null;
                $baseDay = null;

                foreach ($byDate as $day => $figures) {
                    // The base is the newest snapshot at least N days back, but not older than 2 x N days.
                    if ($day <= $limit && $day >= $oldest) {
                        $base = $figures;
                        $baseDay = $day;
                    }
                }

                $changes[$back] = $base === null ? null : $latest['bytes'] - $base['bytes'];
                $bases[$back] = $baseDay;
            }

            $since = Carbon::parse($date)->subDays($days)->toDateString();

            $result[$target] = [
                ...$latest,
                'date' => $date,
                'changes' => $changes,
                'bases' => $bases,
                'series' => array_values(array_map(fn (array $figures): int => $figures['bytes'], array_filter($byDate, fn (string $day): bool => $day >= $since, ARRAY_FILTER_USE_KEY))),
            ];
        }

        return $result;
    }
}
