<?php

use App\Enums\AiFieldKind;
use App\Support\Content\AiFill\AiAnswerParser;
use App\Support\Content\AiFill\AiAnswerResult;
use App\Support\Content\AiFill\AiField;

function aiFields(): array
{
    return [
        new AiField('name', 'admin.catalog.name', AiFieldKind::Plain, 40, uzRequired: true),
        new AiField('body', 'admin.catalog.description', AiFieldKind::Html, 200),
        new AiField('note', 'admin.catalog.description', AiFieldKind::Plain, 100, multiline: true),
    ];
}

function parseAi(string $raw): AiAnswerResult
{
    return (new AiAnswerParser)->parse($raw, aiFields());
}

function aiErrorMessages(AiAnswerResult $result): array
{
    return array_map(fn (array $error): string => $error['message'], $result->errors);
}

it('reads a plain JSON answer for every locale', function () {
    $result = parseAi('{"name":{"uz":"Skaner","ru":"Сканер","en":"Scanner"}}');

    expect($result->values)->toBe(['name' => ['uz' => 'Skaner', 'ru' => 'Сканер', 'en' => 'Scanner']])
        ->and($result->errors)->toBe([])
        ->and($result->warnings)->toBe([]);
});

it('accepts one code fence and a BOM', function () {
    $json = '{"name":{"uz":"Skaner"}}';

    expect(parseAi("```json\n{$json}\n```")->values)->toBe(['name' => ['uz' => 'Skaner']])
        ->and(parseAi("\xEF\xBB\xBF{$json}")->values)->toBe(['name' => ['uz' => 'Skaner']])
        ->and(parseAi("```\n{$json}\n```")->values)->toBe(['name' => ['uz' => 'Skaner']]);
});

it('rejects invalid, array, scalar, deep and oversized answers with one fixed error', function (string $raw, string $message) {
    $result = parseAi($raw);

    expect($result->values)->toBe([])
        ->and(aiErrorMessages($result))->toBe([$message]);
})->with([
    'not json' => ['hello', 'admin.ai_fill.errors.invalid_json'],
    'truncated' => ['{"name":{"uz":"x"', 'admin.ai_fill.errors.invalid_json'],
    'invalid utf-8' => ["{\"name\":{\"uz\":\"\xB1\xB1\"}}", 'admin.ai_fill.errors.invalid_json'],
    'too deep' => ['{"name":{"uz":{"a":{"b":{"c":"x"}}}}}', 'admin.ai_fill.errors.invalid_json'],
    'list root' => ['[{"name":{"uz":"x"}}]', 'admin.ai_fill.errors.not_object'],
    'empty object' => ['{}', 'admin.ai_fill.errors.not_object'],
    'scalar' => ['"text"', 'admin.ai_fill.errors.invalid_json'],
    'oversized' => ['{"name":{"uz":"'.str_repeat('a', AiAnswerParser::MAX_BYTES).'"}}', 'admin.ai_fill.errors.too_large'],
]);

it('ignores unknown keys and locales with one summary warning that never names them', function () {
    $result = parseAi('{"name":{"uz":"Skaner","de":"Scanner"},"price":{"uz":"1"},"slug":{"uz":"x"},"sku":"A"}');

    expect($result->values)->toBe(['name' => ['uz' => 'Skaner']])
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['message'])->toBe('admin.ai_fill.warnings.unknown_keys')
        ->and($result->warnings[0]['params'])->toBe(['count' => 4])
        ->and(json_encode($result))->not->toContain('price')->not->toContain('slug');
});

it('leaves null and empty values alone and rejects non-strings', function () {
    $result = parseAi('{"name":{"uz":"Skaner","ru":null,"en":""},"body":{"uz":["a"],"ru":5,"en":true},"note":"text"}');

    expect($result->values)->toBe(['name' => ['uz' => 'Skaner']])
        ->and(aiErrorMessages($result))->toBe([
            'admin.ai_fill.errors.invalid_type',
            'admin.ai_fill.errors.invalid_type',
            'admin.ai_fill.errors.invalid_type',
            'admin.ai_fill.errors.invalid_type',
        ])
        ->and($result->errors[3]['locale'])->toBeNull();
});

it('rejects a value over the field maximum and does not truncate it', function () {
    $result = parseAi(json_encode(['name' => ['uz' => str_repeat('a', 41), 'ru' => str_repeat('я', 40)]]));

    expect($result->values)->toBe(['name' => ['ru' => str_repeat('я', 40)]])
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0])->toMatchArray(['key' => 'name', 'locale' => 'uz', 'message' => 'admin.ai_fill.errors.too_long', 'params' => ['max' => 40]]);
});

it('counts the length of the cleaned html, not of the raw text', function () {
    $raw = '<p>'.str_repeat('a', 100).'</p><script>'.str_repeat('x', 500).'</script>';
    $result = parseAi(json_encode(['body' => ['en' => $raw]]));

    expect($result->values['body']['en'])->toBe('<p>'.str_repeat('a', 100).'</p>');
});

it('sanitizes html: scripts, handlers, javascript links, svg and images are stripped', function () {
    $raw = '<p onclick="x()">Hi</p><script>alert(1)</script><img src=x onerror=alert(1)><svg onload=alert(1)></svg><a href="javascript:alert(1)">bad</a><a href="https://example.com">ok</a>';
    $html = parseAi(json_encode(['body' => ['en' => $raw]]))->values['body']['en'];

    expect($html)->toContain('<p>Hi</p>')->toContain('https://example.com')
        ->not->toContain('script')->not->toContain('onclick')->not->toContain('onerror')
        ->not->toContain('svg')->not->toContain('javascript:')->not->toContain('<img');
});

it('reports html that is empty after cleaning', function () {
    $result = parseAi(json_encode(['body' => ['en' => '<script>alert(1)</script>']]));

    expect($result->values)->toBe([])
        ->and(aiErrorMessages($result))->toBe(['admin.ai_fill.errors.empty_after_clean']);
});

it('rejects tags in plain fields but accepts a lone less-than sign', function () {
    $tags = parseAi(json_encode(['name' => ['en' => 'Scanner <b>X</b>', 'ru' => '<!-- x -->A', 'uz' => '</p>']]));
    $math = parseAi(json_encode(['name' => ['en' => 'IP rating < 65']]));

    expect($tags->values)->toBe([])
        ->and(aiErrorMessages($tags))->toBe(array_fill(0, 3, 'admin.ai_fill.errors.tags_not_allowed'))
        ->and($math->values)->toBe(['name' => ['en' => 'IP rating < 65']]);
});

it('warns about markdown and wraps bare text into paragraphs', function () {
    $result = parseAi(json_encode(['body' => ['en' => "## Title\n\nFirst paragraph.\n\nSecond one."]]));

    expect($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0]['message'])->toBe('admin.ai_fill.warnings.markdown')
        ->and($result->values['body']['en'])->toBe('<p>## Title</p><p>First paragraph.</p><p>Second one.</p>');
});

it('removes control and bidi characters and normalises line breaks', function () {
    $dirty = "A\u{0000}B\u{202E}C\u{2066}D\u{0085}E\u{200B}F\r\nG\rH";
    $result = parseAi(json_encode(['note' => ['en' => $dirty], 'name' => ['en' => "one\ntwo \t three"]]));

    expect($result->values['note']['en'])->toBe("ABCDE\u{200B}F\nG\nH")
        ->and($result->values['name']['en'])->toBe('one two three');
});

it('rejects banned wording in any locale and never echoes it', function (string $locale, string $text) {
    $result = parseAi(json_encode(['body' => [$locale => "<p>{$text}</p>"]]));

    expect($result->values)->toBe([])
        ->and(aiErrorMessages($result))->toBe(['admin.ai_fill.errors.brand_unsafe'])
        ->and(json_encode($result))->not->toContain(mb_strtolower(explode(' ', $text)[0]));
})->with([
    'en discount' => ['en', 'Big discount today'],
    'en cheapest' => ['en', 'The cheapest scanner'],
    'ru discount' => ['ru', 'Скидки для всех'],
    'ru cheaper' => ['ru', 'Дешевле не найти'],
    'uz cheap' => ['uz', 'Arzon narxda'],
    'uz discount' => ['uz', 'Chegirma bor'],
]);

it('catches banned wording hidden between tags and entities', function () {
    $result = parseAi(json_encode(['body' => ['en' => '<p><strong>Dis</strong>count</p><p>x</p>', 'ru' => '<p>скидк&#1080; тут</p>']]));

    expect(aiErrorMessages($result))->toBe(['admin.ai_fill.errors.brand_unsafe']);
});

it('puts the right apostrophes into uz text only', function () {
    $result = parseAi(json_encode(['name' => ['uz' => "O'zbek ma'lumot", 'en' => "it's o'k"]]));

    expect($result->values['name']['uz'])->toBe("O\u{02BB}zbek ma\u{02BC}lumot")
        ->and($result->values['name']['en'])->toBe("it's o'k");
});

it('reports at most ten messages', function () {
    $answer = [];

    foreach (range(1, 30) as $i) {
        $answer['k'.$i] = ['uz' => 'x'];
    }

    $answer['name'] = ['uz' => str_repeat('a', 99), 'ru' => str_repeat('a', 99), 'en' => str_repeat('a', 99)];

    expect(count(parseAi(json_encode($answer))->warnings))->toBeLessThanOrEqual(10);
});
