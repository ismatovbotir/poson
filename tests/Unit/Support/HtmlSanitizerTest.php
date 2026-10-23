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

it('allows only https, mailto and site-relative links', function () {
    expect(HtmlSanitizer::clean('<a href="mailto:info@poson.uz">m</a>'))->toBe('<a href="mailto:info&#64;poson.uz">m</a>')
        ->and(HtmlSanitizer::clean('<a href="/uz/page/about">about</a>'))->toBe('<a href="/uz/page/about">about</a>')
        ->and(HtmlSanitizer::clean('<a href="http://poson.uz">http</a>'))->toBe('<a>http</a>')
        ->and(HtmlSanitizer::clean('<a href="javascript:alert(1)">js</a>'))->toBe('<a>js</a>');
});

it('adds rel=nofollow to external links only', function () {
    config(['app.url' => 'https://poson.uz']);

    expect(HtmlSanitizer::clean('<a href="https://zebra.com/ds2208" target="_blank">ext</a>'))->toBe('<a href="https://zebra.com/ds2208" rel="nofollow">ext</a>')
        ->and(HtmlSanitizer::clean('<a href="https://poson.uz/ru/catalog" rel="nofollow">own</a>'))->toBe('<a href="https://poson.uz/ru/catalog">own</a>')
        ->and(HtmlSanitizer::clean('<a href="https://www.poson.uz/uz">www</a>'))->toBe('<a href="https://www.poson.uz/uz">www</a>')
        ->and(HtmlSanitizer::clean('<a href="https://poson.uz.evil.test/">lookalike</a>'))->toContain('rel="nofollow"')
        ->and(HtmlSanitizer::clean('<a href="/uz/page/contacts">rel</a>'))->not->toContain('nofollow');
});

it('drops protocol-relative links, also on the site host', function (string $href) {
    config(['app.url' => 'https://poson.uz']);

    expect(HtmlSanitizer::clean('<p><a href="'.$href.'">x</a></p>'))->toBe('<p><a>x</a></p>');
})->with([
    'double slash' => '//evil.test/x',
    'own host' => '//poson.uz/uz',
    'triple slash' => '///evil.test',
    'backslashes' => '\\\\evil.test',
    'slash backslash' => '/\\evil.test',
    'tab inside' => "/\t/evil.test",
    'leading space' => ' //evil.test',
    'encoded slashes' => '&#47;&#47;evil.test',
]);

it('treats protocol-relative hrefs as external', function () {
    config(['app.url' => 'https://poson.uz']);

    expect(HtmlSanitizer::isProtocolRelative('//poson.uz/uz'))->toBeTrue()
        ->and(HtmlSanitizer::isExternal('//poson.uz/uz'))->toBeTrue()
        ->and(HtmlSanitizer::isProtocolRelative('/uz/page/about'))->toBeFalse()
        ->and(HtmlSanitizer::isProtocolRelative('https://evil.test'))->toBeFalse();
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
