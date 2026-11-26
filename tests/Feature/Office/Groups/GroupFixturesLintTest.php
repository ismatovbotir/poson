<?php

/*
| Plan 44 T2-2 / T2-5: the group fixtures are hand-written and synthetic. No
| real-looking id, phone, username, invite link or file_id (only the two
| sentinels), and every fixture is used by at least one test.
*/

const GROUP_FIXTURE_SENTINELS = ['FAKE_FILE_ID_MUST_NOT_BE_STORED', 'FAKE_UNIQUE_ID_MUST_NOT_BE_STORED'];

/**
 * @return list<string>
 */
function groupFixtureFiles(): array
{
    return glob(dirname(__DIR__, 3).'/Fixtures/telegram/groups/*.json') ?: [];
}

/**
 * @return list<string> problems found in one decoded fixture
 */
function groupFixtureProblems(mixed $value, string $path = ''): array
{
    $problems = [];

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, ['file_id', 'file_unique_id'], true) && ! in_array($item, GROUP_FIXTURE_SENTINELS, true)) {
                $problems[] = "{$path}.{$key} is not a sentinel";
            }

            if (is_string($key) && in_array($key, ['invite_link', 'vcard', 'thumbnail', 'thumb'], true)) {
                $problems[] = "{$path}.{$key} is not allowed";
            }

            if (is_string($key) && $key === 'username' && (! is_string($item) || preg_match('/^fake_[a-z0-9_]+$/D', $item) !== 1)) {
                $problems[] = "{$path}.username is not fake_*";
            }

            if (is_string($key) && in_array($key, ['date', 'edit_date'], true)) {
                continue;
            }

            $problems = [...$problems, ...groupFixtureProblems($item, $path.'.'.$key)];
        }

        return $problems;
    }

    // 9+ digit ids must be 99900xxxxx (users, basic groups) or -100 + 99900xxxxx (supergroups, channels).
    if (is_int($value) && strlen((string) abs($value)) >= 9 && preg_match('/^(100)?99900\d+$/D', (string) abs($value)) !== 1) {
        $problems[] = "{$path} = {$value} looks like a real id";
    }

    if (is_string($value)) {
        if (preg_match('/t\.me|telegram\.me|joinchat/i', $value) === 1) {
            $problems[] = "{$path} has a t.me link";
        }

        if (preg_match_all('/\+?\d[\d\s\-()]{8,}\d/', $value, $matches) > 0) {
            foreach ($matches[0] as $phone) {
                if (! str_starts_with((string) preg_replace('/\D/', '', $phone), '99890000')) {
                    $problems[] = "{$path} has a real-looking phone";
                }
            }
        }
    }

    return $problems;
}

it('has fixtures and a README with the checked date', function () {
    expect(groupFixtureFiles())->not->toBeEmpty()
        ->and((string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/telegram/groups/README.md'))->toContain('2026-11-26', 'Bot API version');
});

it('keeps every fixture synthetic', function () {
    foreach (groupFixtureFiles() as $file) {
        expect(groupFixtureProblems(json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR)))->toBe([], basename($file));
    }
});

it('catches a real id, a real phone, a file_id, a t.me link and a real username', function () {
    expect(groupFixtureProblems(['chat' => ['id' => -1001234567890]]))->toHaveCount(1)
        ->and(groupFixtureProblems(['chat' => ['id' => 412345678]]))->toHaveCount(1)
        ->and(groupFixtureProblems(['text' => '+998 90 123 45 67']))->toHaveCount(1)
        ->and(groupFixtureProblems(['photo' => ['file_id' => 'AgACAgIAAxkBAAI']]))->toHaveCount(1)
        ->and(groupFixtureProblems(['text' => 'https://t.me/+AbCdEf']))->toHaveCount(1)
        ->and(groupFixtureProblems(['invite_link' => 'x']))->toHaveCount(1)
        ->and(groupFixtureProblems(['from' => ['username' => 'real_person']]))->toHaveCount(1)
        ->and(groupFixtureProblems(['date' => 1764000000, 'chat' => ['id' => -1009990020001], 'from' => ['id' => 9990010001, 'username' => 'fake_client'], 'text' => '+998900000001', 'file_id' => 'FAKE_FILE_ID_MUST_NOT_BE_STORED']))->toBe([]);
});

it('uses every fixture in at least one group test', function () {
    $tests = '';

    foreach (array_merge(glob(__DIR__.'/*Test.php') ?: [], [dirname(__DIR__, 2).'/Security/OfficeGroupsIngestAbuseTest.php']) as $file) {
        if (basename($file) !== basename(__FILE__)) {
            $tests .= (string) file_get_contents($file);
        }
    }

    foreach (groupFixtureFiles() as $file) {
        $name = basename($file, '.json');

        expect(str_contains($tests, "'{$name}'"))->toBeTrue("fixture {$name} is not used by a test");
    }
});
