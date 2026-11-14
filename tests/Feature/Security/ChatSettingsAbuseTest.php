<?php

require_once __DIR__.'/ChatAbuseKit.php';

use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Enums\SettingsArea;
use App\Enums\TelegramDirection;
use App\Models\Setting;
use App\Models\TelegramMessage;
use App\Support\Chat\Adapters\TelegramAdapter;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\Engine;
use App\Support\Chat\RuleIndex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/**
 * Plan 28 security abuse case 25 (section 17) and the stored-rules cap of
 * case 21: a broken settings row never breaks the bot, never keeps a
 * waiting customer from staff and never costs the matcher a query.
 */
beforeEach(fn () => labSetup());

/**
 * @param  array<string, mixed>  $values  name (without "chatbot.") => stored value
 */
function labSettingRows(array $values): void
{
    foreach ($values as $name => $value) {
        Setting::query()->updateOrCreate(['key' => ChatSettings::key($name)], ['group' => SettingsArea::Chatbot->group(), 'value' => $value]);
    }

    ChatSettings::forget();
}

function labInbound(): int
{
    return TelegramMessage::query()->where('direction', TelegramDirection::In)->count();
}

// Abuse case 25
it('falls back to the defaults for broken setting rows', function (mixed $ttl, mixed $shown) {
    labSettingRows(['handoff_ttl_hours' => $ttl, 'products_shown' => $shown, 'hours' => 'oops', 'text.fallback' => 5, 'widget_position' => ['x']]);

    $all = ChatSettings::all();

    expect($all['handoff_ttl_hours'])->toBe(8)
        ->and($all['products_shown'])->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(8)
        ->and(ChatSettings::handoffTtlHours())->toBe(8)
        ->and(ChatSettings::text('fallback', 'en'))->toBeNull();
})->with([
    'text' => ['abc', 'abc'],
    'zero and one' => [0, 1],
    'negative and huge' => [-5, 9999],
    'too long a wait' => [73, 2],
    'float' => [1.5, 4.5],
    'array' => [[8], [5]],
    'null' => [null, null],
]);

// Abuse case 25
it('answers with the default texts when the settings read fails and does not cache the defaults', function () {
    Exceptions::fake();
    labSettingRows(['products_shown' => 7]);
    $fail = true;
    DB::beforeExecuting(function (string $query) use (&$fail): void {
        if ($fail && str_contains($query, '"settings"')) {
            throw new RuntimeException('settings are unavailable');
        }
    });

    $reply = app(Engine::class)->respond(new ChatContext(ChatChannel::Telegram, 'en', 'qwerty zzz'));

    expect($reply->text)->toBe(__('chatbot.fallback', [], 'en'))
        ->and(Cache::get('chatbot.settings.all'))->toBeNull();

    $fail = false;
    app('chatbot.settings.state')->exchangeArray([]);

    expect(ChatSettings::all()['products_shown'])->toBe(7)
        ->and(Cache::get('chatbot.settings.all'))->not->toBeNull();

    Exceptions::assertReported(RuntimeException::class);
});

// Abuse case 25
it('matches a phrase with zero queries even with a broken setting row', function () {
    labSettingRows(['handoff_ttl_hours' => 'abc', 'products_shown' => 'abc']);
    $engine = app(Engine::class);
    $data = app(RuleIndex::class)->load();
    $context = new ChatContext(ChatChannel::Telegram, 'en', 'delivery');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $match = $engine->match($data, $context);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($match)->not->toBeNull()
        ->and($queries)->toBe([]);
});

// Abuse case 25
it('adds at most the one memoized settings query to a reply', function () {
    labSettingRows(['handoff_ttl_hours' => 'abc']);
    app(RuleIndex::class)->load();
    Cache::forget('chatbot.settings.all');
    app('chatbot.settings.state')->exchangeArray([]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $engine = app(Engine::class);
    $engine->respond(new ChatContext(ChatChannel::Telegram, 'en', 'qwerty zzz'));
    $engine->respond(new ChatContext(ChatChannel::Telegram, 'en', 'asdfgh zzz'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(1);
});

// Abuse case 25
it('keeps a customer who waited two hours with staff, whatever the settings say', function (mixed $ttl) {
    labSettingRows(['handoff_ttl_hours' => $ttl]);
    labUser([
        'chat_mode' => ChatMode::Manager,
        'unread_count' => 1,
        'last_inbound_at' => now()->subHours(2),
    ]);

    labTalk('delivery');

    expect(labInbound())->toBe(1);
})->with(['broken row' => 'abc', 'shortest wait' => 1, 'default' => 8]);

// Abuse case 25
it('keeps an answered customer with staff for the default 8 hours when the setting is broken', function () {
    labSettingRows(['handoff_ttl_hours' => 'abc']);
    labUser([
        'chat_mode' => ChatMode::Manager,
        'unread_count' => 0,
        'last_inbound_at' => now()->subHours(3),
        'last_staff_reply_at' => now()->subHours(2),
    ]);

    labTalk('delivery');

    expect(labInbound())->toBe(1);
});

// Abuse case 25
it('lets the bot answer again after the configured wait when the customer is not waiting', function () {
    labSettingRows(['handoff_ttl_hours' => 1]);
    labUser([
        'chat_mode' => ChatMode::Manager,
        'unread_count' => 0,
        'last_inbound_at' => now()->subHours(3),
        'last_staff_reply_at' => now()->subHours(2),
    ]);

    $reply = labTalk('delivery');

    expect(labText($reply))->not->toBe('')
        ->and(labInbound())->toBe(0);
});

// Abuse case 21
it('stores at most ten rule ids in the dialog however many rule buttons an answer has', function () {
    $buttons = collect(range(1, 30))->map(fn (int $id): array => ['type' => 'rule', 'label' => 'Rule '.$id, 'id' => $id])->all();

    $rendered = app(TelegramAdapter::class)->render(new ChatReply('Pick', $buttons), 1);

    expect($rendered['dialog']['rules'])->toHaveCount(10)
        ->and(strlen(json_encode($rendered['dialog'])))->toBeLessThanOrEqual(2048);
});

// Abuse case 21
it('stores at most ten server-side buttons however many an answer has', function () {
    $buttons = collect(range(1, 30))->map(fn (int $id): array => ['type' => 'product', 'label' => 'P '.$id, 'id' => $id])->all();

    $rendered = app(TelegramAdapter::class)->render(new ChatReply('Pick', $buttons), 1);

    expect($rendered['dialog']['buttons'])->toHaveCount(10)
        ->and(strlen(json_encode($rendered['dialog'])))->toBeLessThanOrEqual(2048);
});
