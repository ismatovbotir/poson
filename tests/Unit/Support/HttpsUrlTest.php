<?php

use App\Support\Catalog\HttpsUrl;

it('accepts https links, kept as entered', function (string $url) {
    expect(HttpsUrl::isValid($url))->toBeTrue()
        ->and(HttpsUrl::normalize("  {$url} "))->toBe($url);
})->with([
    'query params' => ['https://billz.io/signup?ref=poson&utm_source=poson&x=%20a+b'],
    'uppercase scheme' => ['HTTPS://BILLZ.IO/'],
    'fragment' => ['https://moysklad.uz/#register'],
    'port' => ['https://partner.uz:8443/r/poson'],
    'IDN unicode' => ['https://пример.рф/регистрация'],
    'IDN punycode' => ['https://xn--e1afmkfd.xn--p1ai/signup'],
    'exactly 2048 bytes' => ['https://partner.uz/?r='.str_repeat('a', 2048 - strlen('https://partner.uz/?r='))],
]);

it('rejects anything else', function (?string $url) {
    expect(HttpsUrl::isValid($url))->toBeFalse();
})->with([
    'null' => [null],
    'empty' => [''],
    'http' => ['http://billz.io'],
    'javascript' => ['javascript:alert(1)'],
    'relative' => ['/signup'],
    'no host' => ['https://'],
    'single label' => ['https://localhost/'],
    'backslash' => ['https://billz.io\\@evil.example/'],
    'zero-width space' => ["https://bil\u{200B}lz.io/"],
    'bidi override' => ["https://billz.io/\u{202E}gpj.exe"],
    'whitespace' => ['https://billz.io/a b'],
    'control character' => ["https://billz.io/\x07"],
    'credentials' => ['https://user:pass@billz.io/'],
    'IPv6 literal' => ['https://[::1]/'],
    'IPv4' => ['https://127.0.0.1/'],
    'port 0' => ['https://billz.io:0/'],
    '2049 bytes' => ['https://partner.uz/?r='.str_repeat('a', 2049 - strlen('https://partner.uz/?r='))],
]);
