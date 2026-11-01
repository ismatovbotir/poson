<?php

use App\Mcp\Support\Period;

it('builds sqlite bucket sql with the Tashkent offset', function () {
    expect(Period::sqlBucket('t.created_at', 'day', 'sqlite'))
        ->toBe("date(datetime(t.created_at, '+5 hours'))")
        ->and(Period::sqlBucket('t.created_at', 'month', 'sqlite'))
        ->toBe("strftime('%Y-%m', datetime(t.created_at, '+5 hours'))")
        ->and(Period::sqlBucket('t.created_at', 'week', 'sqlite'))
        ->toContain("datetime(t.created_at, '+5 hours')", '% 7');
});

it('builds mysql bucket sql with the Tashkent offset', function () {
    $local = 'DATE_ADD(t.created_at, INTERVAL 5 HOUR)';

    expect(Period::sqlBucket('t.created_at', 'day', 'mysql'))->toBe("DATE({$local})")
        ->and(Period::sqlBucket('t.created_at', 'month', 'mysql'))->toBe("DATE_FORMAT({$local}, '%Y-%m')")
        ->and(Period::sqlBucket('t.created_at', 'week', 'mysql'))->toBe("DATE_SUB(DATE({$local}), INTERVAL WEEKDAY({$local}) DAY)");
});

it('buckets sqlite rows at the 23:30 Tashkent boundary', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('create table t (created_at text)');
    // 18:29 UTC = 23:29 Tashkent (Sun 2026-11-01); 19:30 UTC = 00:30 Tashkent (Mon 2026-11-02).
    $pdo->exec("insert into t values ('2026-11-01 18:29:00'), ('2026-11-01 19:30:00')");

    $pick = fn (string $grain) => $pdo->query('select '.Period::sqlBucket('created_at', $grain, 'sqlite').' b from t order by created_at')
        ->fetchAll(PDO::FETCH_COLUMN);

    expect($pick('day'))->toBe(['2026-11-01', '2026-11-02'])
        ->and($pick('week'))->toBe(['2026-10-26', '2026-11-02'])
        ->and($pick('month'))->toBe(['2026-11', '2026-11']);
});
