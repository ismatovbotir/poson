<?php

use App\Support\Chat\Adapters\TelegramAdapter;
use App\Support\Chat\ChatReply;
use App\Support\Telegram\Bot\BotKeyboards;

/**
 * Plan 28 C1: the Telegram adapter's keyboard JSON and the callback pattern (CB-3).
 */
it('renders buttons as one inline keyboard row each and stores the server-side lists', function () {
    $reply = new ChatReply('Tanlang', [
        ['type' => 'rule', 'label' => 'Yetkazib berish', 'id' => 7],
        ['type' => 'faq', 'label' => 'Birinchi savol', 'id' => 31],
        ['type' => 'faq', 'label' => 'Ikkinchi savol', 'id' => 32],
        ['type' => 'url', 'label' => 'Katalog', 'url' => 'https://poson.example.uz/uz/catalog'],
        ['type' => 'handoff', 'label' => 'Menejer'],
        ['type' => 'menu', 'label' => 'Menyu'],
    ]);

    $rendered = (new TelegramAdapter)->render($reply, 4);

    expect($rendered['text'])->toBe('Tanlang')
        ->and(json_encode(TelegramAdapter::markup($rendered['inline']), JSON_UNESCAPED_UNICODE))->toBe(json_encode([
            'inline_keyboard' => [
                [['text' => 'Yetkazib berish', 'callback_data' => 'chat:r:7']],
                [['text' => 'Birinchi savol', 'callback_data' => 'chat:b:4:0']],
                [['text' => 'Ikkinchi savol', 'callback_data' => 'chat:b:4:1']],
                [['text' => 'Katalog', 'url' => 'https://poson.example.uz/uz/catalog']],
                [['text' => 'Menejer', 'callback_data' => 'chat:handoff']],
                [['text' => 'Menyu', 'callback_data' => 'chat:menu']],
            ],
        ], JSON_UNESCAPED_UNICODE))
        ->and($rendered['dialog']['rules'])->toBe([7])
        ->and($rendered['dialog']['buttons'])->toBe([0 => ['t' => 'faq', 'id' => 31], 1 => ['t' => 'faq', 'id' => 32]])
        ->and($rendered['dialog']['gen'])->toBe(4);
});

it('has no keyboard for a reply without buttons', function () {
    $rendered = (new TelegramAdapter)->render(new ChatReply('Faqat matn'));

    expect(TelegramAdapter::markup($rendered['inline']))->toBeNull();
});

it('keeps callback data short enough for Telegram', function () {
    $rendered = (new TelegramAdapter)->render(new ChatReply('x', [['type' => 'rule', 'label' => 'a', 'id' => 999999999]]));

    expect(strlen($rendered['inline'][0][0]['callback_data']))->toBeLessThanOrEqual(64);
});

it('strips control characters from the text and cuts it', function () {
    $rendered = (new TelegramAdapter)->render(new ChatReply("Salom\x00\x07 dunyo\r\nikkinchi".str_repeat('x', 5000)));

    expect($rendered['text'])->toStartWith("Salom dunyo\nikkinchi")
        ->and(mb_strlen($rendered['text']))->toBeLessThanOrEqual(TelegramAdapter::MAX_TEXT_UNITS);
});

it('accepts exactly the chat callback data', function (string $data, ?array $expected) {
    expect(TelegramAdapter::parseCallback($data))->toBe($expected)
        ->and(BotKeyboards::isAllowedCallback($data))->toBe($expected !== null);
})->with([
    ['chat:r:12', ['type' => 'rule', 'id' => 12]],
    ['chat:r:999999999', ['type' => 'rule', 'id' => 999999999]],
    ['chat:b:2:3', ['type' => 'button', 'gen' => 2, 'index' => 3]],
    ['chat:menu', ['type' => 'menu']],
    ['chat:handoff', ['type' => 'handoff']],
    ['chat:cancel', ['type' => 'cancel']],
    ["chat:r:12\n", null],
    ["chat:menu\n", null],
    ['chat:r:', null],
    ['chat:r:-1', null],
    ['chat:r:1234567890', null],
    ['chat:b:3', null],
    ['chat:b:12:3', ['type' => 'button', 'gen' => 12, 'index' => 3]],
    ['chat:b:99:0', ['type' => 'button', 'gen' => 99, 'index' => 0]],
    ['chat:b:100:1', null],
    ['chat:b:1:10', null],
    ["chat:b:1:2\n", null],
    ['chat:b:x:1', null],
    ['chat:r:12:extra', null],
    ['chat:unknown', null],
    ['CHAT:menu', null],
    [' chat:menu', null],
    ['chat:r:١٢', null],
]);

it('keeps the older callback data working', function (string $data) {
    expect(BotKeyboards::isAllowedCallback($data))->toBeTrue();
})->with(['lang:uz', 'lang:ru', 'lang:en', 'skip:company', 'skip:inn', 'name:yes', 'name:change']);

it('renders the cancel button and parses its callback', function () {
    $rendered = (new TelegramAdapter)->render(new ChatReply('Tasdiqlang', [['type' => 'cancel', 'label' => 'Bekor qilish']]), 2);

    expect($rendered['inline'])->toBe([[['text' => 'Bekor qilish', 'callback_data' => 'chat:cancel']]])
        ->and($rendered['dialog']['buttons'])->toBe([])
        ->and(TelegramAdapter::parseCallback('chat:cancel'))->toBe(['type' => 'cancel'])
        ->and(TelegramAdapter::parseCallback("chat:cancel\n"))->toBeNull();
});

it('stores at most ten server-side buttons and drops the rest, keeping hand-off and menu', function () {
    $buttons = [];
    foreach (range(1, 12) as $n) {
        $buttons[] = ['type' => 'product', 'label' => "Product {$n}", 'id' => $n];
    }
    $buttons[] = ['type' => 'handoff', 'label' => 'Menejer'];
    $buttons[] = ['type' => 'menu', 'label' => 'Menyu'];

    $rendered = (new TelegramAdapter)->render(new ChatReply('Topildi', $buttons), 9);

    expect(TelegramAdapter::MAX_STORED)->toBe(10)
        ->and($rendered['dialog']['buttons'])->toHaveCount(10)
        ->and(array_key_last($rendered['dialog']['buttons']))->toBe(9)
        ->and($rendered['dialog']['buttons'][9])->toBe(['t' => 'product', 'id' => 10])
        ->and($rendered['inline'])->toHaveCount(12)
        ->and(collect($rendered['inline'])->flatten(1)->pluck('callback_data')->take(-2)->values()->all())->toBe(['chat:handoff', 'chat:menu']);
});

it('stores every button type of the dialogs and the lookups', function (string $type) {
    $rendered = (new TelegramAdapter)->render(new ChatReply('x', [['type' => $type, 'label' => 'Button', 'id' => 4]]), 1);

    expect($rendered['dialog']['buttons'])->toBe([0 => ['t' => $type, 'id' => 4]])
        ->and($rendered['inline'][0][0]['callback_data'])->toBe('chat:b:1:0');
})->with(['faq', 'product', 'order', 'repair', 'quote', 'send', 'skip']);

it('keeps at most ten distinct offered rule ids', function () {
    $buttons = array_map(fn (int $id) => ['type' => 'rule', 'label' => "Rule {$id}", 'id' => $id], [...range(1, 13), 3, 3]);

    $rendered = (new TelegramAdapter)->render(new ChatReply('x', $buttons));

    expect($rendered['dialog']['rules'])->toBe(range(1, 10));
});
