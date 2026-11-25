<?php

/*
| Plan 41 VO-T0: the Telegram Business fixtures are synthetic. No 9+ digit
| integer outside the fake range 99900xxxxx (dates excluded), no file_id /
| file_unique_id anywhere, phones only in the fake +99890000xxxx range.
*/

/**
 * @return list<string>
 */
function businessFixtureFiles(): array
{
    return glob(dirname(__DIR__, 2).'/Fixtures/telegram/business/*.json') ?: [];
}

/**
 * @return list<string> problems found in one decoded fixture
 */
function businessFixtureProblems(mixed $value, string $path = ''): array
{
    $problems = [];

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, ['file_id', 'file_unique_id', 'thumbnail', 'thumb', 'vcard'], true)) {
                $problems[] = "{$path}.{$key} is not allowed";
            }

            if (is_string($key) && in_array($key, ['date', 'edit_date'], true)) {
                continue;
            }

            $problems = [...$problems, ...businessFixtureProblems($item, $path.'.'.$key)];
        }

        return $problems;
    }

    if (is_int($value) && strlen((string) abs($value)) >= 9 && ! str_starts_with((string) abs($value), '99900')) {
        $problems[] = "{$path} = {$value} looks like a real id (use 99900xxxxx)";
    }

    if (is_string($value) && preg_match_all('/\+?998[\s\-]*\d{2}[\s\-]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/', $value, $matches) > 0) {
        foreach ($matches[0] as $phone) {
            if (! str_starts_with((string) preg_replace('/\D/', '', $phone), '99890000')) {
                $problems[] = "{$path} has a real-looking phone";
            }
        }
    }

    return $problems;
}

it('has fixtures', function () {
    expect(businessFixtureFiles())->not->toBeEmpty();
});

it('keeps every fixture synthetic: fake ids, fake phones, no file_id', function () {
    foreach (businessFixtureFiles() as $file) {
        $raw = (string) file_get_contents($file);

        expect($raw)->not->toContain('file_id')
            ->and(businessFixtureProblems(json_decode($raw, true, 32, JSON_THROW_ON_ERROR)))->toBe([], basename($file));
    }
});

it('catches a real-looking id, a real phone and a file_id', function () {
    expect(businessFixtureProblems(['chat' => ['id' => 412345678]]))->toHaveCount(1)
        ->and(businessFixtureProblems(['text' => '+998 90 123 45 67']))->toHaveCount(1)
        ->and(businessFixtureProblems(['document' => ['file_id' => 'x']]))->toHaveCount(1)
        ->and(businessFixtureProblems(['date' => 1764000000, 'chat' => ['id' => 9990010001]]))->toBe([]);
});
