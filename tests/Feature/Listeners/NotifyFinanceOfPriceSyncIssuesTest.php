<?php

use App\Events\PriceSyncCompleted;
use App\Listeners\NotifyFinanceOfPriceSyncIssues;
use App\Models\ApiToken;
use App\Models\PriceSyncLog;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use App\Support\Telegram\PriceSyncMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const FINANCE_BOT_TOKEN = '123456789:AAFinanceTokenFinanceTokenFinanceT42';

function financeChatConfigured(array $env = []): void
{
    freshSecrets(array_merge([
        'services.telegram.bot_token' => FINANCE_BOT_TOKEN,
        'services.telegram.chats.finance' => '-1003333333333',
    ], $env));
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->telegramFails = 0;
    Http::fake(['api.telegram.org/*' => function () {
        if ($this->telegramFails > 0) {
            $this->telegramFails--;

            return Http::response(['ok' => false], 500);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    }]);
    config(['app.url' => 'https://poson.test']);

    $this->plain = fakeApiTokenValue();
    $this->token = ApiToken::factory()->withPlainText($this->plain)->create(['name' => '1C <office>']);

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
});

afterEach(function () {
    expect(implode("\n", $this->logged))->not->toContain('AAFinanceToken');
});

it('alerts the finance chat in Uzbek about unknown codes, with the first 10 codes and a link', function () {
    financeChatConfigured();
    Product::factory()->create(['sku' => 'KNOWN']);
    $codes = array_map(fn (int $i) => "MISS-{$i}", range(1, 12));

    postPrices(['items' => [priceItem('KNOWN', 10), ...array_map(fn (string $code) => priceItem($code, 10), $codes)]], $this->plain)->assertOk();

    $log = PriceSyncLog::query()->sole();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($log): bool {
        $text = $request['text'];

        return $request['chat_id'] === '-1003333333333'
            && str_contains($text, 'Narxlar sinxronizatsiyasida muammo, paket #'.$log->id)
            && str_contains($text, '1C &lt;office&gt;')
            && str_contains($text, 'nomaʼlum 12')
            && str_contains($text, '<code>MISS-10</code>')
            && ! str_contains($text, 'MISS-11')
            && str_contains($text, 'va yana 2 ta')
            && $request['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://poson.test/admin/price-sync/'.$log->id;
    });
});

it('alerts on invalid items and on a rejected batch', function () {
    financeChatConfigured();

    postPrices(['items' => [priceItem('X', 0)]], $this->plain)->assertOk();
    postPrices('{"items": []}', $this->plain)->assertUnprocessable();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'Paket butunlay rad etildi: items_empty'));
});

it('stays quiet for a clean batch', function () {
    financeChatConfigured();
    Product::factory()->create(['sku' => 'CLEAN']);

    postPrices(['items' => [priceItem('CLEAN', 10)]], $this->plain)->assertOk();

    Http::assertNothingSent();
    expect(app(NotifyFinanceOfPriceSyncIssues::class)->shouldQueue(new PriceSyncCompleted(PriceSyncLog::query()->sole())))->toBeFalse();
});

it('alerts once per token and problem type for 6 hours, whatever the codes', function () {
    financeChatConfigured();

    postPrices(['items' => [priceItem('MISS-A', 10)]], $this->plain)->assertOk();
    // Other unknown codes from the same token: same problem type, no new alert.
    postPrices(['items' => [priceItem('MISS-B', 20), priceItem('MISS-C', 30)]], $this->plain)->assertOk();
    Http::assertSentCount(1);

    // A new problem type (invalid items) alerts, even mixed with the known one.
    postPrices(['items' => [priceItem('MISS-D', 10), priceItem('BAD', 0)]], $this->plain)->assertOk();
    Http::assertSentCount(2);
    postPrices(['items' => [priceItem('BAD-2', -1)]], $this->plain)->assertOk();
    Http::assertSentCount(2);

    // Rejected is its own type.
    postPrices('{"items": []}', $this->plain)->assertUnprocessable();
    Http::assertSentCount(3);

    // Another token has its own window.
    ApiToken::factory()->withPlainText($other = fakeApiTokenValue('oT4'))->create();
    postPrices(['items' => [priceItem('MISS-A', 10)]], $other)->assertOk();
    Http::assertSentCount(4);

    $this->travel(6)->hours();
    $this->travel(1)->minutes();
    postPrices(['items' => [priceItem('MISS-Z', 10)]], $this->plain)->assertOk();
    Http::assertSentCount(5);
});

it('claims the window before sending', function () {
    financeChatConfigured();
    $log = PriceSyncLog::factory()->withUnknown(['MISS'])->create(['api_token_id' => $this->token->id]);

    Cache::add(NotifyFinanceOfPriceSyncIssues::dedupeKey($log, 'unknown'), true, now()->addHour());

    app(NotifyFinanceOfPriceSyncIssues::class)->withFakeQueueInteractions()->handle(new PriceSyncCompleted($log));

    Http::assertNothingSent();
});

it('skips quietly when the finance chat, the token or the toggle is missing', function (array $env, array $settings) {
    freshSecrets($env);

    foreach ($settings as $key => $value) {
        Setting::factory()->create(['key' => $key, 'group' => 'integrations', 'value' => $value]);
    }

    Secrets::flush();

    postPrices(['items' => [priceItem('MISS', 10)]], $this->plain)->assertOk();

    Http::assertNothingSent();
})->with([
    'no finance chat' => [['services.telegram.bot_token' => FINANCE_BOT_TOKEN], []],
    'no bot token' => [['services.telegram.chats.finance' => '-1003333333333'], []],
    'alerts off' => [['services.telegram.bot_token' => FINANCE_BOT_TOKEN, 'services.telegram.chats.finance' => '-1003333333333'], ['telegram.notify_price_sync' => false]],
]);

it('is on by default', function () {
    freshSecrets();

    expect(Secrets::get('telegram.notify_price_sync'))->toBeTrue();
});

it('forgets the claim when the send fails, so the retry sends', function () {
    financeChatConfigured();
    $this->telegramFails = 1;

    $log = PriceSyncLog::factory()->withUnknown(['MISS'])->create(['api_token_id' => $this->token->id]);
    $listener = app(NotifyFinanceOfPriceSyncIssues::class)->withFakeQueueInteractions();

    expect(fn () => $listener->handle(new PriceSyncCompleted($log)))->toThrow(TelegramException::class)
        ->and(Cache::has(NotifyFinanceOfPriceSyncIssues::dedupeKey($log, 'unknown')))->toBeFalse();

    $listener->handle(new PriceSyncCompleted($log));
    Http::assertSentCount(2);
});

it('builds no admin link on plain http', function () {
    config(['app.url' => 'http://poson.test']);
    $log = PriceSyncLog::factory()->withUnknown(['MISS'])->create();

    expect(PriceSyncMessage::for($log, 'uz')->buttons)->toBe([]);
});
