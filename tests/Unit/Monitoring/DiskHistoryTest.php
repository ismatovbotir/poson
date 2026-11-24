<?php

use App\Support\Monitoring\DiskHistory;

function historyRow(string $day, int $bytes): object
{
    return (object) ['target' => 'project_total', 'taken_on' => $day, 'size_bytes' => $bytes, 'file_count' => 1, 'status' => 'ok', 'measured_at' => $day.' 10:00:00'];
}

it('returns a change only when the base snapshot is at most 2 x N days old', function () {
    $rows = collect([historyRow('2026-11-01', 100), historyRow('2026-11-17', 150), historyRow('2026-11-24', 200)]);

    $total = DiskHistory::build($rows, 30)['project_total'];

    expect($total['changes'][7])->toBe(50)
        ->and($total['bases'][7])->toBe('2026-11-17')
        ->and($total['changes'][1])->toBeNull()
        ->and($total['changes'][30])->toBeNull();

    $old = DiskHistory::build(collect([historyRow('2026-10-01', 100), historyRow('2026-11-24', 200)]), 30)['project_total'];

    expect($old['changes'][7])->toBeNull()->and($old['changes'][30])->toBe(100);
});
