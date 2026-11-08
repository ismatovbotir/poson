<?php

use App\Actions\Chat\RecordChatStat;
use App\Actions\Chat\RecordUnmatched;
use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Models\ChatStatDaily;
use App\Models\ChatUnmatched;
use App\Models\TelegramUser;
use App\Support\Chat\ChatRedactor;

/**
 * Plan 28 C1 / CB-10: unmatched phrases are redacted BEFORE they are stored.
 */
dataset('sensitive text', [
    'uz phone international' => ['+998 90 123 45 67 ga qongiroq qiling', ['998', '123', '4567', '45 67']],
    'uz phone local spaces' => ['90 123 45 67 raqamimga yozing', ['123 45', '4567']],
    'uz phone digits only' => ['mening raqamim 901234567', ['901234567']],
    'uz phone with 998' => ['raqam 998901234567', ['998901234567']],
    'uz phone with 8' => ['8 90 123-45-67 qongiroq', ['123-45', '4567']],
    'uz phone brackets' => ['+998 (90) 123-45-67 shu', ['(90)', '123-45']],
    'uz phone dots' => ['90.123.45.67 menga', ['90.123']],
    'card number' => ['karta 8600 1234 5678 9012', ['8600', '5678', '9012']],
    'inn' => ['inn 123456789 bilan', ['123456789']],
    'pinfl' => ['pinfl 31234567890123', ['31234567890123']],
    'passport latin' => ['pasport AA1234567 berdim', ['AA1234567', '1234567']],
    'passport with space' => ['pasport AB 7654321', ['7654321']],
    'passport cyrillic' => ['паспорт АВ1234567', ['1234567']],
    'email' => ['yozing ali.valiyev@example.com ga', ['ali.valiyev', 'example.com', '@']],
    'url https' => ['qarang https://evil.example/path?token=abc123', ['evil.example', 'token']],
    'url www' => ['www.somesite.uz ga kiring', ['somesite']],
    'url telegram' => ['t.me/someone yozing', ['someone']],
    'url bare domain' => ['poson.uz/uz/catalog ochilmayapti', ['poson.uz', 'catalog']],
    'order number' => ['buyurtma O-2026-0001 qani', ['2026', '0001']],
    'non-breaking spaces' => ["raqam 90\u{00A0}123\u{00A0}45\u{00A0}67 shu", ['123', '4567']],
    'thin spaces' => ["raqam 90\u{2009}123\u{2009}45\u{2009}67 shu", ['123']],
    'en dashes' => ["raqam 90\u{2013}123\u{2013}45\u{2013}67 shu", ['123']],
    'minus signs' => ["raqam 90\u{2212}123\u{2212}45\u{2212}67 shu", ['123']],
    'slashes' => ['raqam 90/123/45/67 shu', ['123']],
    'underscores' => ['raqam 90_123_45_67 shu', ['123']],
    'arabic-indic digits' => ["raqam \u{0669}\u{0660}\u{0661}\u{0662}\u{0663}\u{0664}\u{0665}\u{0666}\u{0667} shu", ['٩٠١٢٣']],
    'fullwidth digits' => ["raqam \u{FF19}\u{FF10}\u{FF11}\u{FF12}\u{FF13}\u{FF14}\u{FF15}\u{FF16}\u{FF17} shu", ['９０１']],
    'bot token' => ['token 123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsawXY ishlamaydi', ['AAHdqTcv', '123456789']],
    'bearer' => ['Authorization Bearer abc.def-1234.ghi xato', ['abc.def']],
    'key assignment' => ['api_key=SuperSecret12 xato', ['SuperSecret']],
    'long hex' => ['kod a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4 xato', ['a1b2c3d4e5f6']],
    'long alnum' => ['kod Zx9Qw8Er7Ty6Ui5Op4As3Df2Gh1Jk0 xato', ['Zx9Qw8Er7Ty6']],
    'parol colon' => ['parol: Abc12345 edi', ['Abc12345']],
    'password space' => ['password hunter2 edi', ['hunter2']],
    'parol cyrillic dash' => ['пароль - qwerty1 edi', ['qwerty1']],
    'token colon' => ['token: abcd-efgh edi', ['abcd']],
    'russian token' => ['токен xyz987 edi', ['xyz987']],
    'five digits' => ['kod 12345 keldi', ['12345']],
]);

it('masks personal data before storing sample and normalized', function (string $text, array $leaks) {
    $user = TelegramUser::factory()->registered()->create();

    expect(app(RecordUnmatched::class)->handle($user, 'uz', $text))->toBeTrue();

    $row = ChatUnmatched::query()->sole();

    foreach ($leaks as $leak) {
        expect($row->sample)->not->toContain($leak)
            ->and($row->normalized)->not->toContain($leak)
            ->and(ChatRedactor::clean($text))->not->toContain($leak);
    }

    expect($row->sample)->toContain('#')
        ->and($row->normalized)->not->toContain('#')
        ->and(preg_match('/\d{5,}/', $row->sample.$row->normalized))->toBe(0);
})->with('sensitive text');

it('keeps short model numbers readable', function () {
    expect(ChatRedactor::clean('ZD421 printer 300 dpi'))->toBe('ZD421 printer 300 dpi')
        ->and(ChatRedactor::clean('TSC TE210 va 4 ta'))->toBe('TSC TE210 va 4 ta');
});

it('records only registered telegram users', function () {
    $visitor = TelegramUser::factory()->unregistered()->create();

    expect(app(RecordUnmatched::class)->handle($visitor, 'uz', 'noaniq savol'))->toBeFalse()
        ->and(ChatUnmatched::query()->count())->toBe(0);
});

it('upserts one row per phrase and counts repeats', function () {
    $user = TelegramUser::factory()->registered()->create();
    $other = TelegramUser::factory()->registered()->create();

    foreach ([$user, $user, $other] as $who) {
        app(RecordUnmatched::class)->handle($who, 'uz', 'Noaniq   SAVOL?!');
    }

    app(RecordUnmatched::class)->handle($user, 'ru', 'Noaniq savol');

    $row = ChatUnmatched::query()->where('locale', 'uz')->sole();

    expect($row->count)->toBe(3)
        ->and($row->normalized)->toBe('noaniq savol')
        ->and($row->channel)->toBe(ChatChannel::Telegram)
        ->and(ChatUnmatched::query()->count())->toBe(2)
        ->and($row->getAttributes())->not->toHaveKeys(['telegram_user_id', 'chat_id', 'user_id']);
});

it('drops empty, masked-only and one-letter phrases', function () {
    $user = TelegramUser::factory()->registered()->create();

    foreach (['', '   ', '+998901234567', '?!', 'a'] as $text) {
        expect(app(RecordUnmatched::class)->handle($user, 'uz', $text))->toBeFalse();
    }

    expect(ChatUnmatched::query()->count())->toBe(0);
});

it('caps unmatched phrases per customer and overall each day', function () {
    config(['chatbot.unmatched.per_day_chat' => 2, 'chatbot.unmatched.per_day_global' => 3]);
    $first = TelegramUser::factory()->registered()->create();
    $second = TelegramUser::factory()->registered()->create();
    $third = TelegramUser::factory()->registered()->create();

    expect(app(RecordUnmatched::class)->handle($first, 'uz', 'bir savol'))->toBeTrue()
        ->and(app(RecordUnmatched::class)->handle($first, 'uz', 'ikki savol'))->toBeTrue()
        ->and(app(RecordUnmatched::class)->handle($first, 'uz', 'uch savol'))->toBeFalse()
        ->and(app(RecordUnmatched::class)->handle($second, 'uz', 'tort savol'))->toBeTrue()
        ->and(app(RecordUnmatched::class)->handle($third, 'uz', 'besh savol'))->toBeFalse()
        ->and(ChatUnmatched::query()->count())->toBe(3);
});

it('stores a very long message cut to the column sizes', function () {
    $user = TelegramUser::factory()->registered()->create();

    app(RecordUnmatched::class)->handle($user, 'uz', str_repeat('uzun gap ', 500));

    $row = ChatUnmatched::query()->sole();

    expect(mb_strlen($row->sample))->toBeLessThanOrEqual(200)
        ->and(mb_strlen($row->normalized))->toBeLessThanOrEqual(120);
});

it('counts stats per day, channel, metric and rule', function () {
    $stats = app(RecordChatStat::class);

    $stats->handle(ChatChannel::Telegram, ChatMetric::Hit, 5);
    $stats->handle(ChatChannel::Telegram, ChatMetric::Hit, 5);
    $stats->handle(ChatChannel::Telegram, ChatMetric::Hit, 6);
    $stats->handle(ChatChannel::Telegram, ChatMetric::Unmatched);

    expect(ChatStatDaily::query()->count())->toBe(3)
        ->and(ChatStatDaily::query()->where('chat_rule_id', 5)->value('count'))->toBe(2)
        ->and(ChatStatDaily::query()->where('metric', ChatMetric::Unmatched->value)->value('chat_rule_id'))->toBe(0);
});
