<?php

use App\Mcp\Support\Period;
use App\Mcp\Support\Presenters\Money;
use App\Mcp\Support\Presenters\Page;
use App\Mcp\Tools\PosonTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

it('rejects invalid days without echoing the input', function (mixed $days) {
    $validator = Validator::make(['days' => $days], Period::rules());

    expect($validator->fails())->toBeTrue();

    foreach ($validator->errors()->all() as $message) {
        expect($message)->not->toContain('abc');
    }
})->with([0, -1, 367, 'abc', 1e9, [[1, 2]]]);

it('accepts days 1 and 366 and defaults to 30', function () {
    expect(Validator::make(['days' => 1], Period::rules())->passes())->toBeTrue()
        ->and(Validator::make(['days' => 366], Period::rules())->passes())->toBeTrue()
        ->and(Period::make([])->days())->toBe(30)
        ->and(Period::make(['days' => 366])->days())->toBe(366);
});

it('validates from and to', function () {
    $rules = Period::rules();

    expect(Validator::make(['from' => '2026-10-01', 'to' => '2026-10-10'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['from' => '2026-10-01'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['from' => '2026-10-10', 'to' => '2026-10-01'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['from' => 'nope', 'to' => '2026-10-01'], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['days' => 5, 'from' => '2026-10-01', 'to' => '2026-10-02'], $rules)->fails())->toBeTrue();

    expect(fn () => Period::make(['from' => '2024-01-01', 'to' => '2026-01-01']))->toThrow(ValidationException::class);
});

it('uses Tashkent days and converts the boundaries to UTC', function () {
    // 2026-10-31 19:30 UTC is already 2026-11-01 00:30 in Tashkent.
    $this->travelTo(CarbonImmutable::parse('2026-10-31 19:30:00', 'UTC'));

    $period = Period::make(['days' => 2]);

    expect($period->fromDate())->toBe('2026-10-31')
        ->and($period->toDate())->toBe('2026-11-01')
        ->and($period->fromUtc()->format('Y-m-d H:i'))->toBe('2026-10-30 19:00')
        ->and($period->toUtc()->format('Y-m-d H:i'))->toBe('2026-11-01 19:00')
        ->and($period->label())->toBe('2026-10-31 – 2026-11-01');

    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:59:00', 'UTC'));

    expect(Period::make(['days' => 1])->toDate())->toBe('2026-10-31');
});

it('builds the previous period of the same length', function () {
    $previous = Period::make(['from' => '2026-10-11', 'to' => '2026-10-20'])->previous();

    expect($previous->fromDate())->toBe('2026-10-01')
        ->and($previous->toDate())->toBe('2026-10-10')
        ->and($previous->days())->toBe(10);
});

it('keeps money as integer tiyin', function () {
    expect(Money::tiyin('12500'))->toBe(12500)
        ->and(Money::tiyin(null))->toBe(0)
        ->and(Money::tiyinOrNull(null))->toBeNull()
        ->and(Money::tiyinOrNull(7))->toBe(7);
});

it('pages with has_more and a 50 row ceiling', function () {
    $rules = Page::rules();

    expect(Validator::make(['per_page' => 51], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['page' => 1001], $rules)->fails())->toBeTrue()
        ->and(Page::make([])->perPage)->toBe(20);

    $query = DB::table('users')->orderBy('id');
    $page = Page::make(['per_page' => 2])->fetch($query, fn (object $row): array => ['id' => (int) $row->id]);

    expect($page['items'])->toBeArray()->and($page['has_more'])->toBeFalse();
});

it('caps the encoded payload at 64 KB, cuts tail rows and never throws', function () {
    $rows = array_map(fn (int $i): array => ['i' => $i, 'text' => str_repeat('я', 100)], range(1, 2000));
    $capped = PosonTool::capPayload(['rows' => $rows, 'total' => 2000]);

    expect($capped['truncated'])->toBeTrue()
        ->and(strlen(json_encode($capped)))->toBeLessThanOrEqual(PosonTool::RESPONSE_CAP_BYTES)
        ->and($capped['rows'])->not->toBeEmpty()
        ->and($capped['rows'][0]['i'])->toBe(1)
        ->and(count($capped['rows']))->toBeLessThan(2000)
        ->and($capped['total'])->toBe(2000);

    $small = PosonTool::capPayload(['rows' => [['i' => 1]]]);
    expect($small['truncated'])->toBeFalse()->and($small['rows'])->toHaveCount(1);

    $huge = PosonTool::capPayload(['rows' => [], 'note' => str_repeat('x', 100000)]);
    expect($huge)->toBe(['rows' => [], 'truncated' => true]);

    $badUtf8 = PosonTool::capPayload(['rows' => [['t' => "\xB1\x31"]]]);
    expect($badUtf8['truncated'])->toBeFalse();
});
