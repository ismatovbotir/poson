<?php

use App\Services\Ai\BrandGuard;

it('returns warnings per locale and leaves clean locales out', function () {
    $warnings = BrandGuard::check([
        'uz' => 'Eng arzon yorliq printeri, chegirma bilan!',
        'ru' => 'Принтер этикеток со скидкой −15%',
        'en' => 'Label printer with setup and official warranty.',
    ]);

    expect($warnings)->toHaveKeys(['uz', 'ru'])
        ->not->toHaveKey('en')
        ->and($warnings['uz'])->toBe(['arzon', 'chegirma'])
        ->and($warnings['ru'])->toContain('скидк', '−15%');
});

it('matches regardless of case and inside HTML', function () {
    expect(BrandGuard::matches('<p>CHEAP and on SALE</p>'))->toBe(['cheap', 'sale'])
        ->and(BrandGuard::matches('Выгодная цена на сканеры'))->toBe(['выгодная цен']);
});

it('does not flag neutral words', function () {
    expect(BrandGuard::check([
        'ru' => 'Транзакция, редакция и акционерное общество.',
        'en' => 'Wholesale buyers get a 10-90% humidity range.',
        'uz' => 'Kafolat va servis markazi.',
    ]))->toBe([]);
});

it('highlights matches and escapes the rest', function () {
    $html = (string) BrandGuard::highlight('<b>Cheap</b> scanner');

    expect($html)->toBe('&lt;b&gt;<mark class="rounded bg-amber-100 px-0.5 text-amber-900" data-brand-warning>Cheap</mark>&lt;/b&gt; scanner');
});
