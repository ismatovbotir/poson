<?php

use App\Support\HtmlSanitizer;

it('keeps the allowed tags', function () {
    $html = '<h2>Title</h2><h3>Sub</h3><p>Text <strong>bold</strong> <em>it</em><br>line</p><ul><li>a</li></ul><ol><li>b</li></ol>'
        .'<table><thead><tr><th>H</th></tr></thead><tbody><tr><td colspan="2">C</td></tr></tbody></table>';

    expect(HtmlSanitizer::clean($html))->toBe(str_replace('<br>', '<br />', $html));
});

it('strips scripts, styles, event handlers and javascript links', function () {
    $clean = HtmlSanitizer::clean(
        '<p onclick="steal()">Hi<script>alert(1)</script><style>p{}</style></p>'
        .'<img src="x" onerror="alert(1)"><a href="javascript:alert(1)">bad</a><iframe src="https://evil.test"></iframe>'
    );

    expect($clean)
        ->not->toContain('script')
        ->not->toContain('alert')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->not->toContain('javascript:')
        ->not->toContain('<img')
        ->not->toContain('iframe')
        ->toContain('<p>Hi</p>')
        ->toContain('bad');
});

it('allows only https and mailto links and adds rel=nofollow', function () {
    expect(HtmlSanitizer::clean('<a href="https://poson.uz" target="_blank">ok</a>'))->toBe('<a href="https://poson.uz" rel="nofollow">ok</a>')
        ->and(HtmlSanitizer::clean('<a href="mailto:info@poson.uz">m</a>'))->toContain('href="mailto:')
        ->and(HtmlSanitizer::clean('<a href="http://poson.uz">http</a>'))->not->toContain('href');
});

it('keeps the text of unwrapped formatting tags', function () {
    expect(HtmlSanitizer::clean('<p><b>bold</b> <span style="color:red">red</span></p><h1>Big</h1>'))->toBe('<p>bold red</p>Big');
});

it('returns null when nothing visible is left', function () {
    expect(HtmlSanitizer::cleanOrNull('<p></p><p><br></p>'))->toBeNull()
        ->and(HtmlSanitizer::cleanOrNull('<script>x</script>'))->toBeNull()
        ->and(HtmlSanitizer::cleanOrNull(null))->toBeNull()
        ->and(HtmlSanitizer::cleanOrNull('<p>ok</p>'))->toBe('<p>ok</p>');
});
