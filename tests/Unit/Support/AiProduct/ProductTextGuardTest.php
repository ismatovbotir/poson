<?php

use App\Support\Content\AiProduct\ProductTextGuard;
use App\Support\HtmlSanitizer;

/**
 * Plan 32 PW-15 (AI-11 - AI-13): the post-check of every AI product text.
 * Links and contacts are removed with a warning, money amounts and discount /
 * low-price wording are errors (the value is not used), and no message ever
 * echoes the matched text. Brand rule: CLAUDE.md "Brand positioning".
 */
beforeEach(function () {
    config(['app.url' => 'https://poson.test']);
    $this->guard = new ProductTextGuard;
});

describe('brand-safe wording', function () {
    it('flags price, discount and low-price wording in every language as an error', function (string $text, string $error) {
        $result = $this->guard->check($text);

        expect($result['errors'])->toContain($error)
            ->and(json_encode($result['errors']).json_encode($result['warnings']))->not->toContain(mb_substr($text, 0, 8));
    })->with([
        'ru discount' => ['Сканер со скидкой для новых клиентов', 'discount'],
        'ru percent discount' => ['Скидка 20% на сканер', 'discount'],
        'ru promo' => ['Участвует в акции этого месяца', 'discount'],
        'ru cheaper' => ['Дешевле, чем у конкурентов', 'discount'],
        'ru lower price' => ['Цена ниже рыночной', 'discount'],
        'uz discount' => ['Hozir chegirma bilan', 'discount'],
        'uz cheap' => ['Arzon va sifatli skaner', 'discount'],
        'en discount' => ['A big discount on this scanner', 'discount'],
        'en cheapest' => ['The cheapest barcode scanner', 'discount'],
        'en low price' => ['Low price guaranteed', 'discount'],
        'en sale' => ['Summer sale', 'discount'],
        'badge' => ['Zebra DS2208 -20%', 'discount'],
        'badge with minus sign' => ['Zebra DS2208 −15 %', 'discount'],
        'percent off' => ['Get 30% off today', 'discount'],
        'old price' => ['Old price 100, now less', 'discount'],
        'ru old price' => ['Старая цена перечёркнута', 'discount'],
        'uz old price' => ['Eski narx emas', 'discount'],
        'sum amount' => ['Narxi 1 200 000 soʻm', 'money'],
        'sum amount ru' => ['Стоит 450 000 сум', 'money'],
        'dollar sign' => ['Only $199 for a scanner', 'money'],
        'usd code' => ['Price 199 USD', 'money'],
        'euro' => ['€ 150 inclusive', 'money'],
        'scale word' => ['Narxi 5 mln soʻm', 'money'],
        'ru scale' => ['Всего 2 млн сум', 'money'],
        'rubles' => ['350 руб.', 'money'],
    ]);

    it('passes ordinary product copy and keeps the service message', function (string $text) {
        $result = $this->guard->check($text);

        expect($result['errors'])->toBe([])->and($result['warnings'])->toBe([])->and($result['text'])->toBe($text);
    })->with([
        'service' => ['Expert selection, setup and integration with an official warranty and fast support.'],
        'ru service' => ['Подбор, настройка и интеграция, официальная гарантия и собственный сервисный центр.'],
        'uz service' => ['Sozlash, integratsiya va rasmiy kafolat bilan yetkazib beramiz.'],
        'dimensions' => ['Size 120 x 80 mm, weight 250 g, 203 dpi, up to 4 inch labels'],
        'ean with label' => ['EAN 4006381333931'],
        'plain product name' => ['Zebra DS2208 reliability'],
    ]);

    it('only warns about a bare percent sign (spec values)', function () {
        $result = $this->guard->check('Humidity up to 90 %');

        expect($result['errors'])->toBe([])->and($result['warnings'])->toBe(['percent']);
    });
});

describe('links and contacts', function () {
    it('removes foreign links from html and keeps the label', function () {
        $result = $this->guard->check('<p>Buy at <a href="https://evil.example/pay">our partner</a> now</p>', html: true);

        expect($result['text'])->toContain('our partner')->not->toContain('evil.example')->not->toContain('<a ')
            ->and($result['warnings'])->toContain('link_removed')->and($result['errors'])->toBe([]);
    });

    it('keeps a link to the own https host, drops the same host over http or with userinfo', function () {
        expect(HtmlSanitizer::siteHosts())->toContain('poson.test');

        $own = $this->guard->check('<p><a href="https://poson.test/uz/products">catalog</a></p>', html: true);
        $http = $this->guard->check('<p><a href="http://poson.test/uz/products">catalog</a></p>', html: true);
        $userinfo = $this->guard->check('See https://poson.test@evil.example/x for details');

        expect($own['text'])->toContain('href="https://poson.test/uz/products"')->and($own['warnings'])->toBe([])
            ->and($http['text'])->not->toContain('<a ')->and($http['warnings'])->toContain('link_removed')
            ->and($userinfo['text'])->not->toContain('evil.example');
    });

    it('removes urls, bare domains, messenger links and dangerous schemes', function (string $text) {
        $result = $this->guard->check($text);

        expect($result['warnings'])->toContain('link_removed')
            ->and($result['text'])->not->toContain('evil')->not->toContain('t.me')->not->toContain('javascript:');
    })->with([
        'https url' => ['Order at https://evil.example/shop today'],
        'bare domain' => ['Order at evil.example today'],
        'telegram link' => ['Write to t.me/evil_manager for help'],
        'tel scheme' => ['Call tel:+998901234567 now'],
        'javascript scheme' => ['Click javascript:alert(1) here'],
        'mailto scheme' => ['Mail mailto:evil@evil.example please'],
    ]);

    it('removes emails, handles and phone-like numbers with a contact warning', function (string $text, string $gone) {
        $result = $this->guard->check($text);

        expect($result['warnings'])->toContain('contact_removed')->and($result['text'])->not->toContain($gone);
    })->with([
        'email' => ['Write to sales.evil@mail.example for details', 'sales.evil'],
        'handle' => ['Contact @evil_manager now', '@evil_manager'],
        'phone' => ['Call +998 90 123 45 67 now', '123 45 67'],
        'long digit run' => ['Call 998901234567 now', '998901234567'],
    ]);

    it('keeps a labelled EAN or GTIN', function () {
        $result = $this->guard->check('GTIN: 4006381333931 and EAN-13 4006381333931');

        expect($result['text'])->toContain('4006381333931')->and($result['warnings'])->toBe([]);
    });

    it('never echoes the matched text in an error or a warning', function () {
        $result = $this->guard->check('Скидка 20% at https://evil.example, call +998901234567, mail a@evil.example');

        expect(json_encode([$result['errors'], $result['warnings']]))->not->toContain('evil')->not->toContain('998')
            ->and($result['errors'])->toContain('discount');
    });
});
