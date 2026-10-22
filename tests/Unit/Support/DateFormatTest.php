<?php

use App\Support\DateFormat;
use Carbon\CarbonImmutable;

it('writes Uzbek dates in Latin script', function () {
    $date = CarbonImmutable::parse('2026-10-21 14:05');

    expect(DateFormat::iso($date, 'D MMM, HH:mm', 'uz'))->toBe('21 Okt, 14:05')
        ->and(DateFormat::iso($date, 'dddd, D MMMM YYYY', 'uz'))->toBe('Chorshanba, 21 Oktabr 2026')
        ->and(DateFormat::iso($date, 'D MMM', 'uz'))->not->toContain('окт')
        ->and(DateFormat::iso($date, 'D MMM', 'ru'))->toBe('21 окт')
        ->and(DateFormat::iso($date, 'D MMM', 'en'))->toBe('21 Oct')
        ->and(DateFormat::iso(null, 'D MMM'))->toBeNull()
        ->and(DateFormat::carbonLocale('uz'))->toBe('uz_Latn');
});

it('uses the current locale by default', function () {
    app()->setLocale('uz');

    expect(DateFormat::iso(CarbonImmutable::parse('2026-10-21'), 'MMMM'))->toBe('Oktabr');
});
