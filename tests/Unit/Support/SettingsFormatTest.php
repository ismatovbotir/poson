<?php

use App\Enums\SocialNetwork;
use App\Rules\BrandSafeText;
use App\Rules\SocialUrl;
use App\Support\Settings\SettingsFormat;
use Illuminate\Support\Facades\Validator;

it('accepts valid and rejects invalid values for every format', function (string $pattern, array $valid, array $invalid) {
    foreach ($valid as $value) {
        expect(SettingsFormat::match($value, $pattern))->toBe(trim($value), "should accept [{$value}]");
    }

    foreach ($invalid as $value) {
        expect(SettingsFormat::match($value, $pattern))->toBe('', "should reject [{$value}]");
    }
})->with([
    'ga4' => [SettingsFormat::GA4, ['G-AB12CD34EF', 'G-1234'], ['UA-12345-1', 'g-ab12cd34ef', 'G-', 'GTM-ABC123', 'G-AB12<script>', "G-AB12\nCD"]],
    'metrika' => [SettingsFormat::METRIKA, ['98765432', '12345'], ['1234', '012345', 'ym(98765432)', '98765432a', '-98765432']],
    'google verification' => [SettingsFormat::VERIFICATION_GOOGLE, ['abcDEF123_-abcDEF123_-xyz'], ['short', '<meta name="google-site-verification" content="abc">', 'abc def ghi jkl mno pqr stu', 'abcDEF123"abcDEF123_-xyz']],
    'yandex verification' => [SettingsFormat::VERIFICATION_YANDEX, ['0123456789abcdef', '0123456789ABCDEF'], ['0123456789abcde', 'zzzz456789abcdef', '"><script>']],
    'bing verification' => [SettingsFormat::VERIFICATION_BING, ['0123456789ABCDEF0123456789ABCDEF'], ['0123456789ABCDEF', '0123456789ABCDEF0123456789ABCDEG']],
    'inn' => [SettingsFormat::INN, ['301234567'], ['30123456', '3012345678', '30123456a']],
    'vat' => [SettingsFormat::VAT, ['326070012345'], ['32607001234', '3260700123456']],
    'mfo' => [SettingsFormat::MFO, ['00014'], ['0014', '000140']],
    'account' => [SettingsFormat::ACCOUNT, ['20208000123456789001'], ['2020800012345678900', '2020800012345678900a']],
    'oked' => [SettingsFormat::OKED, ['46510'], ['4651', '465100']],
    'time' => [SettingsFormat::TIME, ['09:00', '23:59', '00:00'], ['9:00', '24:00', '12:60', '12-00']],
    'coordinate' => [SettingsFormat::COORDINATE, ['41.311081', '-69.24', '41'], ['41,31', '41.3abc', '1e5', '41.123456789']],
]);

it('accepts social links only as https on the network hosts', function (SocialNetwork $network, string $url, bool $allowed) {
    expect($network->allows($url))->toBe($allowed);

    $validator = Validator::make(['url' => $url], ['url' => [new SocialUrl($network)]]);

    expect($validator->passes())->toBe($allowed);
})->with([
    'telegram' => [SocialNetwork::Telegram, 'https://t.me/poson_uz', true],
    'instagram www' => [SocialNetwork::Instagram, 'https://www.instagram.com/poson.uz', true],
    'youtube short' => [SocialNetwork::Youtube, 'https://youtu.be/abc', true],
    'http' => [SocialNetwork::Telegram, 'http://t.me/poson_uz', false],
    'javascript' => [SocialNetwork::Telegram, 'javascript:alert(1)//t.me/x', false],
    'iframe' => [SocialNetwork::Youtube, '<iframe src="https://www.youtube.com/embed/x"></iframe>', false],
    'foreign host' => [SocialNetwork::Instagram, 'https://instagram.com.evil.example/poson', false],
    'subdomain trick' => [SocialNetwork::Telegram, 'https://evil.t.me.example.com/x', false],
    'credentials' => [SocialNetwork::Facebook, 'https://user:pass@facebook.com/poson', false],
    'port' => [SocialNetwork::Facebook, 'https://facebook.com:8443/poson', false],
    'host only' => [SocialNetwork::Linkedin, 'https://www.linkedin.com/', false],
    'other network' => [SocialNetwork::Whatsapp, 'https://t.me/poson', false],
    'share query' => [SocialNetwork::Instagram, 'https://www.instagram.com/poson.uz?igsh=abc123', true],
    'quote in path' => [SocialNetwork::Instagram, 'https://www.instagram.com/poson"onmouseover=x', false],
    'angle brackets' => [SocialNetwork::Telegram, 'https://t.me/poson%3Cscript%3E<b>', false],
    'fragment' => [SocialNetwork::Telegram, 'https://t.me/poson#x', false],
    'odd query' => [SocialNetwork::Youtube, 'https://youtube.com/@poson?x=<y>', false],
]);

it('rejects a social link without a known network', function () {
    expect(Validator::make(['url' => 'https://t.me/x'], ['url' => [new SocialUrl(null)]])->fails())->toBeTrue();
});

it('blocks discount and low-price wording with BrandSafeText', function (string $text) {
    $validator = Validator::make(['text' => $text], ['text' => [new BrandSafeText]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('text'))->not->toContain($text);
})->with(['акция', 'Акции месяца', 'подешевле', '−20%', '-15 %', 'katta chegirma', 'Chegirmalar', 'arzon narx', 'Скидки до 30', 'дешевле всех', 'Big discount', 'cheapest scanners', 'Sale!', 'promo']);

it('allows service wording with BrandSafeText', function (string $text) {
    expect(Validator::make(['text' => $text], ['text' => [new BrandSafeText]])->passes())->toBeTrue();
})->with([
    '12 oy kafolat', 'Сервисный центр', 'Wholesale delivery', '24/7 support', '100% original',
    'Акционерное общество', 'Акционерно-коммерческий банк', 'редакция', 'транзакция', 'реакция',
    'акцизный налог', 'Влажность 10-90%', 'Pazarzona',
]);

it('shares one banned word list with the tests', function () {
    expect(bannedWordingPatterns())->toBe(config('brand.banned_patterns'))
        ->and(config('brand.banned_patterns'))->not->toBeEmpty();
});
